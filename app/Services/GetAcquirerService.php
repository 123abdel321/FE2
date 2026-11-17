<?php

namespace App\Services;

use Lopezsoft\UBL21dian\Templates\SOAP\GetAcquirer;
use RuntimeException;

class GetAcquirerService
{
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

        $getAcquirer = new GetAcquirer(
            $this->certificatePath,
            $this->certificatePassword
        );

        $getAcquirer->identificationType   = $identificationType;
        $getAcquirer->identificationNumber = $identificationNumber;
        $getAcquirer->To = 'https://vpfe.dian.gov.co/WcfDianCustomerServices.svc';

        // signToSend() firma el SOAP y lo envía. Devuelve un Client.
        $client = $getAcquirer->signToSend();

        // getResponseToObject() convierte la respuesta XML a objeto
        $response = $client->getResponseToObject();

        // La respuesta de GetAcquirer viene dentro de Envelope->Body->GetAcquirerResponse->GetAcquirerResult
        $result = $response->Envelope->Body->GetAcquirerResponse->GetAcquirerResult ?? null;

        if (!$result) {
            throw new RuntimeException('La DIAN no devolvió GetAcquirerResult: ' . json_encode($response));
        }

        return [
            'name'  => $result->ReceiverName ?? null,
            'email' => $result->ReceiverEmail ?? null,
        ];
    }
}