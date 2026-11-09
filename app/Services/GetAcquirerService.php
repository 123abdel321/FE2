<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;
use RuntimeException;

class GetAcquirerService
{
    private const SOAP_NS  = 'http://www.w3.org/2003/05/soap-envelope';
    private const WCF_NS   = 'http://wcf.dian.colombia';
    private const WSA_NS   = 'http://www.w3.org/2005/08/addressing';
    private const WSSE_NS  = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';
    private const WSU_NS   = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd';
    private const DSIG_NS  = 'http://www.w3.org/2000/09/xmldsig#';

    private const X509_VALUE_TYPE = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-x509-token-profile-1.0#X509v3';
    private const BASE64_ENCODING = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-soap-message-security-1.0#Base64Binary';

    // private string $endpoint   = 'https://vpfe.dian.gov.co/WcfDianCustomerServices.svc';
    private string $endpoint   = 'https://vpfe-hab.dian.gov.co/WcfDianCustomerServices.svc';
    private string $soapAction = 'http://wcf.dian.colombia/IWcfDianCustomerServices/GetAcquirer';

    public function __construct(
        private string $certificatePath,
        private string $certificatePassword
    ) {
        if (!is_file($this->certificatePath) || !is_readable($this->certificatePath)) {
            throw new RuntimeException('No se encuentra el certificado digital o no tiene permisos de lectura.');
        }
    }

    public function consult(string $identificationType, string $identificationNumber): array
    {
        if (trim($identificationType) === '' || trim($identificationNumber) === '') {
            throw new RuntimeException('El tipo y número de identificación son obligatorios.');
        }

        $doc       = $this->buildSoapEnvelope($identificationType, $identificationNumber);
        $signedXml = $this->signXml($doc);
        
        $response = Http::withOptions(['connect_timeout' => 15, 'timeout' => 45])
            ->withBody($signedXml, 'application/soap+xml; charset=utf-8; action="' . $this->soapAction . '"')
            ->post($this->endpoint);

        dd($response);

        $body = $response->body();

        if (!$response->successful()) {
            throw new RuntimeException(
                'DIAN respondió HTTP ' . $response->status() . ': ' .
                ($this->extractSoapFault($body) ?? mb_substr(strip_tags($body), 0, 1500))
            );
        }

        return $this->parseResponse($body);
    }

    private function buildSoapEnvelope(string $type, string $number): DOMDocument
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput       = false;

        $envelope = $doc->createElementNS(self::SOAP_NS, 'soap:Envelope');
        $doc->appendChild($envelope);

        $header = $doc->createElementNS(self::SOAP_NS, 'soap:Header');
        $body   = $doc->createElementNS(self::SOAP_NS, 'soap:Body');
        $envelope->appendChild($header);
        $envelope->appendChild($body);

        // WS-Addressing Action
        $header->appendChild($doc->createElementNS(self::WSA_NS, 'wsa:Action', $this->soapAction));

        // WS-Addressing To
        $to = $doc->createElementNS(self::WSA_NS, 'wsa:To', $this->endpoint);
        $to->setAttributeNS(self::WSU_NS, 'wsu:Id', 'To-' . bin2hex(random_bytes(12)));
        $header->appendChild($to);

        // Operación GetAcquirer
        $operation = $doc->createElementNS(self::WCF_NS, 'wcf:GetAcquirer');
        $operation->appendChild($doc->createElementNS(self::WCF_NS, 'wcf:identificationType', $type));
        $operation->appendChild($doc->createElementNS(self::WCF_NS, 'wcf:identificationNumber', $number));
        $body->appendChild($operation);

        return $doc;
    }

    private function signXml(DOMDocument $doc): string
    {
        $pkcs12 = file_get_contents($this->certificatePath);
        if ($pkcs12 === false) {
            throw new RuntimeException('No se pudo leer el certificado digital.');
        }

        if (!openssl_pkcs12_read($pkcs12, $certificates, $this->certificatePassword)) {
            throw new RuntimeException('No se pudo abrir el certificado P12. Verifica su contraseña.');
        }

        if (empty($certificates['pkey']) || empty($certificates['cert'])) {
            throw new RuntimeException('El certificado no contiene una llave privada o certificado X509 válido.');
        }

        $privateKey = $certificates['pkey'];
        $publicCert = $certificates['cert'];

        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('wsa', self::WSA_NS);
        $to = $xpath->query('//wsa:To')->item(0);

        if (!$to instanceof DOMElement) {
            throw new RuntimeException('No se encontró wsa:To para realizar la firma.');
        }

        // WS-Security
        $header   = $doc->getElementsByTagNameNS(self::SOAP_NS, 'Header')->item(0);
        $security = $doc->createElementNS(self::WSSE_NS, 'wsse:Security');
        $security->setAttributeNS(self::SOAP_NS, 'soap:mustUnderstand', '1');
        $header->appendChild($security);

        // BinarySecurityToken
        $certificateId     = 'X509-' . bin2hex(random_bytes(12));
        $certificateBase64 = preg_replace('/-----BEGIN CERTIFICATE-----|-----END CERTIFICATE-----|\s+/', '', $publicCert);

        $binaryToken = $doc->createElementNS(self::WSSE_NS, 'wsse:BinarySecurityToken', $certificateBase64);
        $binaryToken->setAttribute('EncodingType', self::BASE64_ENCODING);
        $binaryToken->setAttribute('ValueType', self::X509_VALUE_TYPE);
        $binaryToken->setAttributeNS(self::WSU_NS, 'wsu:Id', $certificateId);
        $security->appendChild($binaryToken);

        // Timestamp
        $timestamp = $doc->createElementNS(self::WSU_NS, 'wsu:Timestamp');
        $timestamp->setAttributeNS(self::WSU_NS, 'wsu:Id', 'TS-' . bin2hex(random_bytes(12)));

        $now     = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $created = $doc->createElementNS(self::WSU_NS, 'wsu:Created', $now->format('Y-m-d\TH:i:s.v\Z'));
        $expires = $doc->createElementNS(self::WSU_NS, 'wsu:Expires', $now->modify('+60 seconds')->format('Y-m-d\TH:i:s.v\Z'));

        $timestamp->appendChild($created);
        $timestamp->appendChild($expires);
        $security->appendChild($timestamp);

        // Firma XML
        $signature = new XMLSecurityDSig('ds');
        $signature->setCanonicalMethod(XMLSecurityDSig::EXC_C14N);

        $signature->addReference($to, XMLSecurityDSig::SHA256, [XMLSecurityDSig::EXC_C14N], [
            'id_name'   => 'Id',
            'prefix'    => 'wsu',
            'prefix_ns' => self::WSU_NS,
            'overwrite' => false,
        ]);

        $key = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type' => 'private']);
        $key->loadKey($privateKey, false);
        $signature->sign($key);
        $signatureNode = $signature->appendSignature($security, true);

        // SecurityTokenReference
        $keyInfo        = $doc->createElementNS(self::DSIG_NS, 'ds:KeyInfo');
        $tokenReference = $doc->createElementNS(self::WSSE_NS, 'wsse:SecurityTokenReference');
        $reference      = $doc->createElementNS(self::WSSE_NS, 'wsse:Reference');

        $reference->setAttribute('URI', '#' . $certificateId);
        $reference->setAttribute('ValueType', self::X509_VALUE_TYPE);

        $tokenReference->appendChild($reference);
        $keyInfo->appendChild($tokenReference);
        $signatureNode->appendChild($keyInfo);

        return $doc->saveXML();
    }

    private function parseResponse(string $body): array
    {
        $doc = new DOMDocument();
        if (!@$doc->loadXML($body, LIBXML_NONET)) {
            throw new RuntimeException('La DIAN devolvió una respuesta XML inválida.');
        }

        $fault = $this->extractSoapFault($body);
        if ($fault !== null) {
            throw new RuntimeException('SOAP Fault DIAN: ' . $fault);
        }

        $xpath  = new DOMXPath($doc);
        $result = $xpath->query('//*[local-name()="GetAcquirerResult"]')->item(0);

        if (!$result) {
            throw new RuntimeException(
                'No se encontró GetAcquirerResult en la respuesta DIAN: ' . mb_substr(strip_tags($body), 0, 1000)
            );
        }

        $getValue = function (array $names) use ($xpath, $result): ?string {
            foreach ($names as $name) {
                $value = trim((string) $xpath->evaluate(
                    'string(.//*[local-name()="' . $name . '"][1])',
                    $result
                ));
                if ($value !== '') {
                    return $value;
                }
            }
            return null;
        };

        return [
            'name'  => $getValue(['ReceiverName', 'Name']),
            'email' => $getValue(['ReceiverEmail', 'Email']),
        ];
    }

    private function extractSoapFault(string $body): ?string
    {
        $doc = new DOMDocument();
        if (!@$doc->loadXML($body, LIBXML_NONET)) {
            return null;
        }

        $xpath = new DOMXPath($doc);
        $fault = $xpath->query('//*[local-name()="Fault"]')->item(0);

        if (!$fault) {
            return null;
        }

        $message = trim((string) $xpath->evaluate('string((.//*[local-name()="Text"])[1])', $fault));
        if ($message === '') {
            $message = trim((string) $xpath->evaluate('string((.//*[local-name()="faultstring"])[1])', $fault));
        }

        return $message ?: 'Error SOAP sin descripción.';
    }
}