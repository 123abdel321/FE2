<?php

namespace App\Http\Controllers\Api;

use App\User;
use App\Company;
use App\TaxTotal;
use App\PaymentForm;
use App\TypeDocument;
use App\TypeCurrency;
use App\TypeOperation;
use App\PaymentMethod;
use App\AllowanceCharge;
use App\BillingReference;
use App\LegalMonetaryTotal;
use App\Document;
use App\HealthField;
use Illuminate\Http\Request;
use App\Traits\DocumentTrait;
use App\Http\Controllers\Controller;
use App\Municipality;
use App\OrderReference;
use App\InvoicePeriod;
use App\InvoiceLine as CreditNoteLine;
use App\Http\Requests\Api\CreditNoteRequest;
use ubl21dian\XAdES\SignCreditNote;
use ubl21dian\XAdES\SignAttachedDocument;
use ubl21dian\Templates\SOAP\SendBillAsync;
use ubl21dian\Templates\SOAP\SendBillSync;
use ubl21dian\Templates\SOAP\SendTestSetAsync;
use Illuminate\Support\Facades\Mail;
use App\Mail\InvoiceMail;
use DateTime;
use Carbon\Carbon;

class CreditNoteController extends Controller
{
    use DocumentTrait;

    /**
     * Store.
     *
     * @param \App\Http\Requests\Api\CreditNoteRequest $request
     *
     * @return \Illuminate\Http\Response
     */

    public function store(CreditNoteRequest $request)
    {
        // User
        $user = auth()->user();
        $smtp_parameters = collect($request->smtp_parameters);
        if (isset($request->smtp_parameters)) {
            \Config::set('mail.host', $smtp_parameters->toArray()['host']);
            \Config::set('mail.port', $smtp_parameters->toArray()['port']);
            \Config::set('mail.username', $smtp_parameters->toArray()['username']);
            \Config::set('mail.password', $smtp_parameters->toArray()['password']);
            \Config::set('mail.encryption', $smtp_parameters->toArray()['encryption']);
        } else {
            if ($user->validate_mail_server()) {
                \Config::set('mail.host', $user->mail_host);
                \Config::set('mail.port', $user->mail_port);
                \Config::set('mail.username', $user->mail_username);
                \Config::set('mail.password', $user->mail_password);
                \Config::set('mail.encryption', $user->mail_encryption);
            }
        }

        // User company
        $company = $user->company;

        // Verify Certificate
        $certificate_days_left = 0;
        $c = $this->verify_certificate();
        if (!$c['success']) {
            return $c;
        } else {
            $certificate_days_left = $c['certificate_days_left'];
        }

        if ($company->type_plan->state == false) {
            return [
                'status'  => 400,
                'success' => false,
                'message' => 'El plan en el que esta registrado la empresa se encuentra en el momento INACTIVO para enviar documentos electronicos...',
            ];
        }

        if ($company->state == false) {
            return [
                'status'  => 400,
                'success' => false,
                'message' => 'La empresa se encuentra en el momento INACTIVA para enviar documentos electronicos...',
            ];
        }

        if ($company->type_plan->period != 0 && $company->absolut_plan_documents == 0) {
            $firstDate  = new DateTime($company->start_plan_date);
            $secondDate = new DateTime(Carbon::now()->format('Y-m-d H:i'));
            $intvl      = $firstDate->diff($secondDate);

            switch ($company->type_plan->period) {
                case 1:
                    if ($intvl->y >= 1 || $intvl->m >= 1 || $this->qty_docs_period() >= $company->type_plan->qty_docs_invoice) {
                        return [
                            'status'  => 400,
                            'success' => false,
                            'message' => 'La empresa ha llegado al limite de tiempo/documentos del plan por mensualidad, por favor renueve su membresia...',
                        ];
                    }
                    break;
                case 2:
                    if ($intvl->y >= 1 || $this->qty_docs_period() >= $company->type_plan->qty_docs_invoice) {
                        return [
                            'status'  => 400,
                            'success' => false,
                            'message' => 'La empresa ha llegado al limite de tiempo/documentos del plan por anualidad, por favor renueve su membresia...',
                        ];
                    }
                    break;
                case 3:
                    if ($this->qty_docs_period() >= $company->type_plan->qty_docs_invoice) {
                        return [
                            'status'  => 400,
                            'success' => false,
                            'message' => 'La empresa ha llegado al limite de documentos del plan por paquetes, por favor renueve su membresia...',
                        ];
                    }
                    break;
            }
        } else {
            if ($company->absolut_plan_documents != 0) {
                if ($this->qty_docs_period("ABSOLUT") >= $company->absolut_plan_documents) {
                    return [
                        'status'  => 400,
                        'success' => false,
                        'message' => 'La empresa ha llegado al limite de documentos del plan mixto, por favor renueve su membresia...',
                    ];
                }
            }
        }

        // Actualizar Tablas
        $this->ActualizarTablas();

        // Document
        $invoice_doc = new Document();
        $invoice_doc->request_api         = json_encode($request->all());
        $invoice_doc->state_document_id   = 0;
        $invoice_doc->type_document_id    = $request->type_document_id;
        $invoice_doc->number              = $request->number;
        $invoice_doc->client_id           = 1;
        $invoice_doc->client              = $request->customer;
        $invoice_doc->currency_id         = 35;
        $invoice_doc->date_issue          = date("Y-m-d H:i:s");
        $invoice_doc->sale                = 1000;
        $invoice_doc->total_discount      = 100;
        $invoice_doc->taxes               = $request->tax_totals;
        $invoice_doc->total_tax           = 150;
        $invoice_doc->subtotal            = 800;
        $invoice_doc->total               = 1200;
        $invoice_doc->version_ubl_id      = 1;
        $invoice_doc->ambient_id          = 1;
        $invoice_doc->identification_number = $company->identification_number;

        // Type document
        $typeDocument = TypeDocument::findOrFail($request->type_document_id);
        if ($request->is_eqdoc) {
            $is_eqdoc = true;
            $pf  = strtoupper($typeDocument->prefix);
            $pfs = strtoupper($typeDocument->prefix) . "S";
        } else {
            $is_eqdoc = false;
            $pf  = "NC";
            $pfs = "NCS";
        }

        // Customer
        $customerAll = collect($request->customer);
        if (isset($customerAll['municipality_id_fact'])) {
            $customerAll['municipality_id'] = Municipality::where('codefacturador', $customerAll['municipality_id_fact'])->first();
        }
        $customer = new User($customerAll->toArray());

        // Customer company
        $customer->company = new Company($customerAll->toArray());

        // Type operation id
        if (!$request->type_operation_id) {
            $request->type_operation_id = 12;
        }
        $typeoperation = TypeOperation::findOrFail($request->type_operation_id);

        // Currency id
        if (isset($request->idcurrency) and (!is_null($request->idcurrency))) {
            $idcurrency           = TypeCurrency::findOrFail($request->idcurrency);
            $calculationrate      = $request->calculationrate;
            $calculationratedate  = $request->calculationratedate;
        } else {
            $idcurrency           = TypeCurrency::findOrFail($invoice_doc->currency_id);
            $calculationrate      = 1;
            $calculationratedate  = Carbon::now()->format('Y-m-d');
        }

        // Resolution
        $request->resolution->number = $request->number;
        $resolution = $request->resolution;

        if (env('VALIDATE_BEFORE_SENDING', false)) {
            $doc = Document::where('type_document_id', $request->type_document_id)
                ->where('identification_number', $company->identification_number)
                ->where('prefix', $resolution->prefix)
                ->where('number', $request->number)
                ->where('state_document_id', 1)
                ->get();

            if (count($doc) > 0) {
                return [
                    'status'  => 400,
                    'success' => false,
                    'message' => 'Este documento ya fue enviado anteriormente, se registra en la base de datos.',
                    'response' => [
                        'customer' => $doc[0]->customer,
                        'cude'     => $doc[0]->cufe,
                        'sale'     => $doc[0]->total,
                    ],
                ];
            }
        }

        // Date time
        $date = $request->date;
        $time = $request->time;

        // Notes
        $notes = $request->notes;

        // Order Reference
        if ($request->order_reference) {
            $orderreference = new OrderReference($request->order_reference);
        } else {
            $orderreference = NULL;
        }

        // Invoice Period
        if ($request->invoice_period) {
            $invoice_period = new InvoicePeriod($request->invoice_period);
        } else {
            $invoice_period = NULL;
        }

        // Health Fields
        if ($request->health_fields) {
            $healthfields = new HealthField($request->health_fields);
        } else {
            $healthfields = NULL;
        }

        // Discrepancy response
        $discrepancycode        = $request->discrepancyresponsecode;
        $discrepancydescription = $request->discrepancyresponsedescription;

        // Payment form default
        $paymentFormAll = (object) array_merge($this->paymentFormDefault, $request->payment_form ?? []);
        $paymentForm    = PaymentForm::findOrFail($paymentFormAll->payment_form_id);
        $paymentForm->payment_method_code = PaymentMethod::findOrFail($paymentFormAll->payment_method_id)->code;
        $paymentForm->payment_due_date    = $paymentFormAll->payment_due_date ?? null;
        $paymentForm->duration_measure    = $paymentFormAll->duration_measure ?? null;

        // Allowance charges
        $allowanceCharges = collect();
        foreach ($request->allowance_charges ?? [] as $allowanceCharge) {
            $allowanceCharges->push(new AllowanceCharge($allowanceCharge));
        }

        // Tax totals
        $taxTotals = collect();
        foreach ($request->tax_totals ?? [] as $taxTotal) {
            $taxTotals->push(new TaxTotal($taxTotal));
        }

        // Retenciones globales
        $withHoldingTaxTotal = collect();
        foreach ($request->with_holding_tax_total ?? [] as $item) {
            $withHoldingTaxTotal->push(new TaxTotal($item));
        }

        // Legal monetary totals
        $legalMonetaryTotals = new LegalMonetaryTotal($request->legal_monetary_totals);

        // Credit note lines
        $creditNoteLines = collect();
        foreach ($request->credit_note_lines as $creditNoteLine) {
            $creditNoteLines->push(new CreditNoteLine($creditNoteLine));
            if (isset($creditNoteLine['is_RNDC']) && $creditNoteLine['is_RNDC'] == TRUE) {
                $request->isTransport = TRUE;
            }
        }

        // Billing reference
        if (!$request->billing_reference) {
            $billingReference = NULL;
        } else {
            $billingReference = new BillingReference($request->billing_reference);
            if ($is_eqdoc) {
                $billingReference->setSchemeNameAttribute("CUDE-SHA384");
                $billingReference->setDocumentTypeCodeAttribute(
                    TypeDocument::where('id', $request->billing_reference['type_document_id'])->firstOrFail()->code
                );
            }
        }

        // Create XML
        if (isset($request->is_RNDC) && $request->is_RNDC == TRUE) {
            $request->isTransport = TRUE;
        }

        $crediNote = $this->createXML(compact(
            'user', 'company', 'customer', 'taxTotals', 'withHoldingTaxTotal',
            'resolution', 'paymentForm', 'typeDocument', 'creditNoteLines',
            'allowanceCharges', 'legalMonetaryTotals', 'billingReference',
            'date', 'time', 'notes', 'typeoperation', 'orderreference',
            'discrepancycode', 'discrepancydescription', 'request',
            'idcurrency', 'calculationrate', 'calculationratedate',
            'healthfields', 'invoice_period'
        ));

        // Register Customer
        if (env('APPLY_SEND_CUSTOMER_CREDENTIALS', TRUE)) {
            $this->registerCustomer($customer, $request->sendmail);
        } else {
            $this->registerCustomer($customer, $request->send_customer_credentials);
        }

        // Signature XML
        $signCreditNote = new SignCreditNote($company->certificate->path, $company->certificate->password);
        if ($is_eqdoc) {
            $signCreditNote->softwareID = $company->software->identifier_eqdocs;
            $signCreditNote->pin        = $company->software->pin_eqdocs;
        } else {
            $signCreditNote->softwareID = $company->software->identifier;
            $signCreditNote->pin        = $company->software->pin;
        }

        // Directorios
        if ($request->GuardarEn) {
            if (!is_dir($request->GuardarEn)) {
                mkdir($request->GuardarEn);
            }
            $basePath = $request->GuardarEn;
        } else {
            $basePath = storage_path("app/public/{$company->identification_number}");
            if (!is_dir($basePath)) {
                mkdir($basePath);
            }
        }

        if ($request->GuardarEn) {
            $signCreditNote->GuardarEn = $basePath . "\\{$pf}-{$resolution->next_consecutive}.xml";
        } else {
            $signCreditNote->GuardarEn = $basePath . "/{$pf}-{$resolution->next_consecutive}.xml";
        }

        $sendBillSync = new SendBillSync($company->certificate->path, $company->certificate->password);
        if ($is_eqdoc) {
            $sendBillSync->To = $company->software->url_eqdocs;
        } else {
            $sendBillSync->To = $company->software->url;
        }
        $sendBillSync->fileName = "{$resolution->next_consecutive}.xml";

        if ($request->GuardarEn) {
            $sendBillSync->contentFile = $this->zipBase64(
                $company, $resolution, $signCreditNote->sign($crediNote),
                $basePath . "\\{$pfs}-{$resolution->next_consecutive}"
            );
        } else {
            $sendBillSync->contentFile = $this->zipBase64(
                $company, $resolution, $signCreditNote->sign($crediNote),
                $basePath . "/{$pfs}-{$resolution->next_consecutive}"
            );
        }

        $QRStr = $this->createPDF(
            $user, $company, $customer, $typeDocument, $resolution,
            $date, $time, $paymentForm, $request,
            $signCreditNote->ConsultarCUDE(), "NC",
            $withHoldingTaxTotal, $notes, $healthfields
        );

        $invoice_doc->prefix              = $resolution->prefix;
        $invoice_doc->customer            = $customer->company->identification_number;
        $invoice_doc->xml                 = "{$pfs}-{$resolution->next_consecutive}.xml";
        $invoice_doc->pdf                 = "{$pfs}-{$resolution->next_consecutive}.pdf";
        $invoice_doc->client_id           = $customer->company->identification_number;
        $invoice_doc->client              = $request->customer;
        if (property_exists($request, 'id_currency')) {
            $invoice_doc->currency_id = $request->id_currency;
        } else {
            $invoice_doc->currency_id = 35;
        }
        $invoice_doc->date_issue          = date("Y-m-d H:i:s");
        $invoice_doc->sale                = $legalMonetaryTotals->payable_amount;
        $invoice_doc->total_discount      = $legalMonetaryTotals->allowance_total_amount ?? 0;
        $invoice_doc->taxes               = $request->tax_totals;
        $invoice_doc->total_tax           = $legalMonetaryTotals->tax_inclusive_amount - $legalMonetaryTotals->tax_exclusive_amount;
        $invoice_doc->subtotal            = $legalMonetaryTotals->line_extension_amount;
        $invoice_doc->total               = $legalMonetaryTotals->payable_amount;
        $invoice_doc->version_ubl_id      = 2;
        if ($is_eqdoc) {
            $invoice_doc->ambient_id = $company->eqdocs_type_environment_id;
        } else {
            $invoice_doc->ambient_id = $company->type_environment_id;
        }
        $invoice_doc->identification_number = $company->identification_number;
        $invoice_doc->save();

        $filename      = '';
        $respuestadian = '';
        $typeDocument  = TypeDocument::findOrFail(7);
        $ar            = new \DOMDocument;

        // Rutas de request/response DIAN
        if ($request->GuardarEn) {
            $reqPath  = $basePath . "\\Req{$pf}-{$resolution->next_consecutive}.xml";
            $rptaPath = $basePath . "\\Rpta{$pf}-{$resolution->next_consecutive}.xml";
        } else {
            $reqPath  = $basePath . "/Req{$pf}-{$resolution->next_consecutive}.xml";
            $rptaPath = $basePath . "/Rpta{$pf}-{$resolution->next_consecutive}.xml";
        }

        try {
            $respuestadian = $sendBillSync
                ->signToSend($reqPath)
                ->getResponseToObject($rptaPath);

            // Servicio DIAN no disponible
            if (isset($respuestadian->html)) {
                return [
                    'status'  => 500,
                    'success' => false,
                    'message' => "El servicio DIAN no se encuentra disponible en el momento, reintente mas tarde...",
                    'response' => [
                        'unexpected'      => ["Servicio DIAN no disponible"],
                        'mensajesValidacion' => null,
                    ],
                ];
            }

            $result = $respuestadian->Envelope->Body->SendBillSyncResponse->SendBillSyncResult;

            // ======================== CASO ÉXITO ========================
            if ($result->IsValid == 'true') {
                $filename = str_replace(
                    ['nc', 'ads', 'dse', 'ni', 'nd', 'ncq', 'fv'],
                    'ad',
                    $result->XmlFileName
                );
                if ($request->atacheddocument_name_prefix) {
                    $filename = $request->atacheddocument_name_prefix . $filename;
                }

                $cufecude = $result->XmlDocumentKey;
                $invoice_doc->state_document_id = 1;
                $invoice_doc->cufe              = $cufecude;
                $invoice_doc->save();

                // Attached Document
                $signedxml      = file_get_contents(storage_path("app/xml/{$company->id}/" . $result->XmlFileName . ".xml"));
                $td             = strpos($signedxml, "</Invoice>") > 0
                    ? '/Invoice'
                    : (strpos($signedxml, "</CreditNote>") > 0 ? '/CreditNote' : '/DebitNote');
                $appresponsexml = base64_decode($result->XmlBase64Bytes);
                $ar->loadXML($appresponsexml);
                $fechavalidacion = $ar->documentElement->getElementsByTagName('IssueDate')->item(0)->nodeValue;
                $horavalidacion  = $ar->documentElement->getElementsByTagName('IssueTime')->item(0)->nodeValue;
                $document_number = $this->ValueXML($signedxml, $td . "/cbc:ID/");

                $attacheddocument = $this->createXML(compact(
                    'user', 'company', 'customer', 'resolution', 'typeDocument',
                    'cufecude', 'signedxml', 'appresponsexml',
                    'fechavalidacion', 'horavalidacion', 'document_number'
                ));

                $signAttachedDocument = new SignAttachedDocument($company->certificate->path, $company->certificate->password);
                $signAttachedDocument->GuardarEn = $basePath . "/{$filename}.xml";

                $at   = $signAttachedDocument->sign($attacheddocument)->xml;
                $file = fopen($basePath . "/{$filename}.xml", "w");
                fwrite($file, $at);
                fclose($file);

                if (isset($request->annexes)) {
                    $this->saveAnnexes($request->annexes, $filename);
                }

                // Buscar invoice para mailing
                $invoice = Document::where('identification_number', '=', $company->identification_number)
                    ->where('customer', '=', $customer->company->identification_number)
                    ->where('prefix', '=', $this->ValueXML($signedxml, $td . "/cac:AccountingSupplierParty/cac:Party/cac:PartyLegalEntity/cac:CorporateRegistrationScheme/cbc:ID/"))
                    ->where('number', '=', str_replace(
                        $this->ValueXML($signedxml, $td . "/cac:AccountingSupplierParty/cac:Party/cac:PartyLegalEntity/cac:CorporateRegistrationScheme/cbc:ID/"),
                        '',
                        $this->ValueXML($signedxml, $td . "/cbc:ID/")
                    ))
                    ->where('state_document_id', '=', 1)
                    ->get();

                $send_email_success   = 0;
                $send_email_date_time = null;

                if (isset($request->sendmail) && $request->sendmail && count($invoice) > 0) {
                    try {
                        Mail::to($customer->email)->send(new InvoiceMail($invoice, $customer, $company, FALSE, FALSE, $filename, TRUE, $request));
                        if ($request->sendmailtome) {
                            Mail::to($user->email)->send(new InvoiceMail($invoice, $customer, $company, FALSE, FALSE, $filename, FALSE, $request));
                        }
                        if ($request->email_cc_list) {
                            foreach ($request->email_cc_list as $email) {
                                Mail::to($email)->send(new InvoiceMail($invoice, $customer, $company, FALSE, FALSE, $filename, FALSE, $request));
                            }
                        }
                        $invoice[0]->send_email_success   = 1;
                        $invoice[0]->send_email_date_time = Carbon::now()->format('Y-m-d H:i');
                        $invoice[0]->save();
                        $send_email_success   = 1;
                        $send_email_date_time = $invoice[0]->send_email_date_time;
                    } catch (\Exception $m) {
                        \Log::debug($m->getMessage());
                    }
                }

                // Base64 adjuntos
                $invoicexml        = base64_encode(file_get_contents($basePath . "/{$pfs}-{$resolution->next_consecutive}.xml"));
                $zipinvoicexml     = base64_encode(file_get_contents($basePath . "/{$pfs}-{$resolution->next_consecutive}.zip"));
                $unsignedinvoicexml= base64_encode(file_get_contents($basePath . "/{$pf}-{$resolution->next_consecutive}.xml"));
                $reqfe             = base64_encode(file_get_contents($reqPath));
                $rptafe            = base64_encode(file_get_contents($rptaPath));

                return [
                    'status'  => 200,
                    'success' => true,
                    'response' => [
                        'cufe'              => $cufecude,
                        'StatusDescription' => "{$typeDocument->name} #{$resolution->next_consecutive} generada con éxito",
                        'ResponseDian'      => $respuestadian,
                        'XmlUrl'            => "xml/{$company->id}/" . $result->XmlFileName . ".xml",
                        'ZipUrl'            => "zip/{$company->id}/" . $result->XmlFileName . ".zip",
                        'ZipBase64Bytes'    => base64_encode($this->getZIP()),
                        // extras
                        'invoicexml'          => $invoicexml,
                        'zipinvoicexml'       => $zipinvoicexml,
                        'unsignedinvoicexml'  => $unsignedinvoicexml,
                        'reqfe'               => $reqfe,
                        'rptafe'              => $rptafe,
                        'attacheddocument'    => base64_encode($at),
                        'urlinvoicexml'       => "{$pfs}-{$resolution->next_consecutive}.xml",
                        'urlinvoicepdf'       => "{$pfs}-{$resolution->next_consecutive}.pdf",
                        'urlinvoiceattached'  => "{$filename}.xml",
                        'cude'                => $signCreditNote->ConsultarCUDE(),
                        'QRStr'               => $QRStr,
                        'certificate_days_left' => $certificate_days_left,
                        'send_email_success'    => $send_email_success,
                        'send_email_date_time'  => $send_email_date_time,
                    ],
                ];
            }

            // ======================== CASO RECHAZO DIAN ========================
            $prev       = false;
            $unexpected = [];

            // Normalizar ErrorMessage->string a array
            if (isset($result->ErrorMessage->string) && !is_array($result->ErrorMessage->string)) {
                $result->ErrorMessage->string = [$result->ErrorMessage->string];
            } elseif (!isset($result->ErrorMessage->string)) {
                $result->ErrorMessage->string = [
                    $result->StatusDescription ?? '',
                    "Código de paquete enviado: {$resolution->next_consecutive}",
                    "Tipo de documento: {$resolution->type_document_id}",
                ];
            }

            foreach ($result->ErrorMessage->string as $item) {
                if (is_string($item) && strpos($item, ": 90") > 0) {
                    $prev = true;
                } else {
                    $unexpected[] = $item;
                }
            }

            // Marcar documento como rechazado si no fue "ya procesado"
            if (!$prev) {
                // opcional: $invoice_doc->state_document_id = 2; $invoice_doc->save();
            }

            return [
                'status'  => 400,
                'success' => false,
                'response' => [
                    'cufe'               => $prev ? $signCreditNote->ConsultarCUDE() : null,
                    'unexpected'         => $unexpected,
                    'sendResponse'       => $respuestadian,
                    'mensaje'            => $result->StatusDescription,
                    'mensajesValidacion' => $result->ErrorMessage,
                    'requestedBase64'    => base64_encode($signCreditNote->sign($crediNote)->xml),
                ],
            ];

        } catch (\Exception $e) {
            return [
                'status'  => 500,
                'success' => false,
                'response' => [
                    'unexpected'         => [$e->getMessage()],
                    'mensajesValidacion' => preg_replace("/[\r\n|\n|\r]+/", "", json_encode($respuestadian)),
                    'sendResponse'       => $respuestadian,
                ],
                'message' => $e->getMessage() . ' ' . preg_replace("/[\r\n|\n|\r]+/", "", json_encode($respuestadian)),
            ];
        }
    }

    /**
     * Test set store description.
     *
     * @param \App\Http\Requests\Api\CreditNoteRequest $request
     *
     * @return \Illuminate\Http\Response
     */
    public function testSetStore(CreditNoteRequest $request, $testSetId)
    {
        // User
        $user = auth()->user();

        // User company
        $company = $user->company;

        // Verify Certificate
        $certificate_days_left = 0;
        $c = $this->verify_certificate();
        if(!$c['success'])
            return $c;
        else
            $certificate_days_left = $c['certificate_days_left'];

        // Actualizar Tablas
        $this->ActualizarTablas();

        //Document
        $invoice_doc = new Document();
        $invoice_doc->request_api = json_encode($request->all());
        $invoice_doc->state_document_id = 0;
        $invoice_doc->type_document_id = $request->type_document_id;
        $invoice_doc->number = $request->number;
        $invoice_doc->client_id = 1;
        $invoice_doc->client =  $request->customer ;
        $invoice_doc->currency_id = 35;
        $invoice_doc->date_issue = date("Y-m-d H:i:s");
        $invoice_doc->sale = 1000;
        $invoice_doc->total_discount = 100;
        $invoice_doc->taxes =  $request->tax_totals;
        $invoice_doc->total_tax = 150;
        $invoice_doc->subtotal = 800;
        $invoice_doc->total = 1200;
        $invoice_doc->version_ubl_id = 1;
        $invoice_doc->ambient_id = 1;
        $invoice_doc->identification_number = $company->identification_number;
//        $invoice_doc->save();

        // Type document
        $typeDocument = TypeDocument::findOrFail($request->type_document_id);
        if($request->is_eqdoc){
            $is_eqdoc = true;
            $pf = strtoupper($typeDocument->prefix);
            $pfs = strtoupper($typeDocument->prefix)."S";
        }
        else{
            $is_eqdoc = false;
            $pf = "NC";
            $pfs = "NCS";
        }

        // Customer
        $customerAll = collect($request->customer);
        if(isset($customerAll['municipality_id_fact']))
            $customerAll['municipality_id'] = Municipality::where('codefacturador', $customerAll['municipality_id_fact'])->first();
        $customer = new User($customerAll->toArray());

        // Customer company
        $customer->company = new Company($customerAll->toArray());

        // Type operation id
        if(!$request->type_operation_id)
            $request->type_operation_id = 12;
        $typeoperation = TypeOperation::findOrFail($request->type_operation_id);

        // Currency id
        if(isset($request->idcurrency) and (!is_null($request->idcurrency))){
            $idcurrency = TypeCurrency::findOrFail($request->idcurrency);
            $calculationrate = $request->calculationrate;
            $calculationratedate = $request->calculationratedate;
        }
        else{
            $idcurrency = TypeCurrency::findOrFail($invoice_doc->currency_id);
            $calculationrate = 1;
            $calculationratedate = Carbon::now()->format('Y-m-d');
        }

        // Resolution
        $request->resolution->number = $request->number;
        $resolution = $request->resolution;

        if(env('VALIDATE_BEFORE_SENDING', false)){
            $doc = Document::where('type_document_id', $request->type_document_id)->where('identification_number', $company->identification_number)->where('prefix', $resolution->prefix)->where('number', $request->number)->where('state_document_id', 1)->get();
            if(count($doc) > 0)
                return [
                    'success' => false,
                    'message' => 'Este documento ya fue enviado anteriormente, se registra en la base de datos.',
                    'customer' => $doc[0]->customer,
                    'cude' => $doc[0]->cufe,
                    'sale' => $doc[0]->total,
                ];
        }

        // Date time
        $date = $request->date;
        $time = $request->time;

        // Notes
        $notes = $request->notes;

        // Order Reference
        if($request->order_reference)
            $orderreference = new OrderReference($request->order_reference);
        else
            $orderreference = NULL;

        // Health Fields
        if($request->health_fields)
            $healthfields = new HealthField($request->health_fields);
        else
            $healthfields = NULL;

        // Discrepancy response
        $discrepancycode = $request->discrepancyresponsecode;
        $discrepancydescription = $request->discrepancyresponsedescription;

        // Payment form default
        $paymentFormAll = (object) array_merge($this->paymentFormDefault, $request->payment_form ?? []);
        $paymentForm = PaymentForm::findOrFail($paymentFormAll->payment_form_id);
        $paymentForm->payment_method_code = PaymentMethod::findOrFail($paymentFormAll->payment_method_id)->code;
        $paymentForm->payment_due_date = $paymentFormAll->payment_due_date ?? null;
        $paymentForm->duration_measure = $paymentFormAll->duration_measure ?? null;

        // Allowance charges
        $allowanceCharges = collect();
        foreach ($request->allowance_charges ?? [] as $allowanceCharge) {
            $allowanceCharges->push(new AllowanceCharge($allowanceCharge));
        }

        // Tax totals
        $taxTotals = collect();
        foreach ($request->tax_totals ?? [] as $taxTotal) {
            $taxTotals->push(new TaxTotal($taxTotal));
        }

        // Retenciones globales
        $withHoldingTaxTotal = collect();
//        $withHoldingTaxTotalCount = 0;
//        $holdingTaxTotal = $request->holding_tax_total;
        foreach($request->with_holding_tax_total ?? [] as $item) {
//            $withHoldingTaxTotalCount++;
//            $holdingTaxTotal = $request->holding_tax_total;
            $withHoldingTaxTotal->push(new TaxTotal($item));
        }

        // Legal monetary totals
        $legalMonetaryTotals = new LegalMonetaryTotal($request->legal_monetary_totals);

        // Credit note lines
        $creditNoteLines = collect();
        foreach ($request->credit_note_lines as $creditNoteLine) {
            $creditNoteLines->push(new CreditNoteLine($creditNoteLine));
            if(isset($creditNoteLine['is_RNDC']) && $creditNoteLine['is_RNDC'] == TRUE)
                $request->isTransport = TRUE;
        }

        // Billing reference
        if(!$request->billing_reference)
            $billingReference = NULL;
        else{
            $billingReference = new BillingReference($request->billing_reference);
            if($is_eqdoc){
                $billingReference->setSchemeNameAttribute("CUDE-SHA384");
                $billingReference->setDocumentTypeCodeAttribute(TypeDocument::where('id', $request->billing_reference['type_document_id'])->firstOrFail()->code);
            }
        }

        // Create XML
        if(isset($request->is_RNDC) && $request->is_RNDC == TRUE)
            $request->isTransport = TRUE;
        $crediNote = $this->createXML(compact('user', 'company', 'customer', 'taxTotals', 'withHoldingTaxTotal', 'resolution', 'paymentForm', 'typeDocument', 'creditNoteLines', 'allowanceCharges', 'legalMonetaryTotals', 'billingReference', 'date', 'time', 'notes', 'typeoperation', 'orderreference', 'discrepancycode', 'discrepancydescription', 'request', 'idcurrency', 'calculationrate', 'calculationratedate', 'healthfields'));
//        return $crediNote->saveXML();

        // Register Customer
        if(env('APPLY_SEND_CUSTOMER_CREDENTIALS', TRUE))
            $this->registerCustomer($customer, $request->sendmail);
        else
            $this->registerCustomer($customer, $request->send_customer_credentials);

        // Signature XML
        $signCreditNote = new SignCreditNote($company->certificate->path, $company->certificate->password);
        if($is_eqdoc){
            $signCreditNote->softwareID = $company->software->identifier_eqdocs;
            $signCreditNote->pin = $company->software->pin_eqdocs;
        }
        else{
            $signCreditNote->softwareID = $company->software->identifier;
            $signCreditNote->pin = $company->software->pin;
        }

        if ($request->GuardarEn){
            if (!is_dir($request->GuardarEn)) {
                mkdir($request->GuardarEn);
            }
        }
        else{
            if (!is_dir(storage_path("app/public/{$company->identification_number}"))) {
                mkdir(storage_path("app/public/{$company->identification_number}"));
            }
        }

        if ($request->GuardarEn)
            $signCreditNote->GuardarEn = $request->GuardarEn."\\{$pf}-{$resolution->next_consecutive}.xml";
        else
            $signCreditNote->GuardarEn = storage_path("app/public/{$company->identification_number}/{$pf}-{$resolution->next_consecutive}.xml");

        $sendTestSetAsync = new SendTestSetAsync($company->certificate->path, $company->certificate->password);
        if($is_eqdoc)
            $sendTestSetAsync->To = $company->software->url_eqdocs;
        else
            $sendTestSetAsync->To = $company->software->url;

        $sendTestSetAsync->fileName = "{$resolution->next_consecutive}.xml";
        if ($request->GuardarEn)
            $sendTestSetAsync->contentFile = $this->zipBase64($company, $resolution, $signCreditNote->sign($crediNote), $request->GuardarEn."\\{$pfs}-{$resolution->next_consecutive}");
        else
            $sendTestSetAsync->contentFile = $this->zipBase64($company, $resolution, $signCreditNote->sign($crediNote), storage_path("app/public/{$company->identification_number}/{$pfs}-{$resolution->next_consecutive}"));
        $sendTestSetAsync->testSetId = $testSetId;

        $QRStr = $this->createPDF($user, $company, $customer, $typeDocument, $resolution, $date, $time, $paymentForm, $request, $signCreditNote->ConsultarCUDE(), "NC", $withHoldingTaxTotal, $notes, $healthfields);

        $invoice_doc->prefix = $resolution->prefix;
        $invoice_doc->customer = $customer->company->identification_number;
        $invoice_doc->xml = "{$pfs}-{$resolution->next_consecutive}.xml";
        $invoice_doc->pdf = "{$pfs}-{$resolution->next_consecutive}.pdf";
        $invoice_doc->client_id = $customer->company->identification_number;
        $invoice_doc->client =  $request->customer ;
        if(property_exists($request, 'id_currency'))
            $invoice_doc->currency_id = $request->id_currency;
        else
            $invoice_doc->currency_id = 35;
        $invoice_doc->date_issue = date("Y-m-d H:i:s");
        $invoice_doc->sale = $legalMonetaryTotals->payable_amount;
        $invoice_doc->total_discount = $legalMonetaryTotals->allowance_total_amount ?? 0;
        $invoice_doc->taxes =  $request->tax_totals;
        $invoice_doc->total_tax = $legalMonetaryTotals->tax_inclusive_amount - $legalMonetaryTotals->tax_exclusive_amount;
        $invoice_doc->subtotal = $legalMonetaryTotals->line_extension_amount;
        $invoice_doc->total = $legalMonetaryTotals->payable_amount;
        $invoice_doc->version_ubl_id = 2;
        if($is_eqdoc)
            $invoice_doc->ambient_id = $company->eqdocs_type_environment_id;
        else
            $invoice_doc->ambient_id = $company->type_environment_id;
        $invoice_doc->identification_number = $company->identification_number;
        $invoice_doc->save();

        if ($request->GuardarEn)
            return [
                'message' => "{$typeDocument->name} #{$resolution->next_consecutive} generada con éxito",
                'ResponseDian' => $sendTestSetAsync->signToSend($request->GuardarEn."\\Req{$pf}-{$resolution->next_consecutive}.xml")->getResponseToObject($request->GuardarEn."\\Rpta{$pf}-{$resolution->next_consecutive}.xml"),
                'invoicexml'=>base64_encode(file_get_contents($request->GuardarEn."\\{$pfs}-{$resolution->next_consecutive}.xml")),
                'zipinvoicexml'=>base64_encode(file_get_contents($request->GuardarEn."\\{$pfs}-{$resolution->next_consecutive}.zip")),
                'unsignedinvoicexml'=>base64_encode(file_get_contents($request->GuardarEn."\\{$pf}-{$resolution->next_consecutive}.xml")),
                'reqfe'=>base64_encode(file_get_contents($request->GuardarEn."\\Req{$pf}-{$resolution->next_consecutive}.xml")),
                'rptafe'=>base64_encode(file_get_contents($request->GuardarEn."\\Rpta{$pf}-{$resolution->next_consecutive}.xml")),
                'urlinvoicexml'=>"{$pfs}-{$resolution->next_consecutive}.xml",
                'urlinvoicepdf'=>"{$pfs}-{$resolution->next_consecutive}.pdf",
                'urlinvoiceattached'=>"Attachment-{$resolution->next_consecutive}.xml",
                'cude' => $signCreditNote->ConsultarCUDE(),
                'QRStr' => $QRStr,
                'certificate_days_left' => $certificate_days_left,
            ];
        else
            return [
                'message' => "{$typeDocument->name} #{$resolution->next_consecutive} generada con éxito",
                'ResponseDian' => $sendTestSetAsync->signToSend(storage_path("app/public/{$company->identification_number}/Req{$pf}-{$resolution->next_consecutive}.xml"))->getResponseToObject(storage_path("app/public/{$company->identification_number}/Rpta{$pf}-{$resolution->next_consecutive}.xml")),
                'invoicexml'=>base64_encode(file_get_contents(storage_path("app/public/{$company->identification_number}/{$pfs}-{$resolution->next_consecutive}.xml"))),
                'zipinvoicexml'=>base64_encode(file_get_contents(storage_path("app/public/{$company->identification_number}/{$pfs}-{$resolution->next_consecutive}.zip"))),
                'unsignedinvoicexml'=>base64_encode(file_get_contents(storage_path("app/public/{$company->identification_number}/{$pf}-{$resolution->next_consecutive}.xml"))),
                'reqfe'=>base64_encode(file_get_contents(storage_path("app/public/{$company->identification_number}/Req{$pf}-{$resolution->next_consecutive}.xml"))),
                'rptafe'=>base64_encode(file_get_contents(storage_path("app/public/{$company->identification_number}/Rpta{$pf}-{$resolution->next_consecutive}.xml"))),
                'urlinvoicexml'=>"{$pfs}-{$resolution->next_consecutive}.xml",
                'urlinvoicepdf'=>"{$pfs}-{$resolution->next_consecutive}.pdf",
                'urlinvoiceattached'=>"Attachment-{$resolution->next_consecutive}.xml",
                'cude' => $signCreditNote->ConsultarCUDE(),
                'QRStr' => $QRStr,
                'certificate_days_left' => $certificate_days_left,
            ];
    }
}
