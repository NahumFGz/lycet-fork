<?php

declare(strict_types=1);

namespace App\Greenter;

use App\Model\DespatchCarrier;
use App\Xml\Builder\DespatchCarrierBuilder;
use Greenter\Api;
use Greenter\Model\DocumentInterface;
use Greenter\Model\Response\BaseResult;
use Greenter\XMLSecLibs\Sunat\SignedXml;

/**
 * `Greenter\Api` + soporte para la Guia de Remision del TRANSPORTISTA (tipoDoc 31).
 *
 * `Api::send()` resuelve el builder del XML por el nombre de la clase del modelo
 * (`XmlBuilderResolver`), que solo conoce los documentos de greenter — un `DespatchCarrier`
 * terminaria buscando un `DespatchCarrierBuilder` dentro de `greenter/xml`, que no existe.
 * Esta subclase intercepta ese caso: arma el XML con la plantilla propia, lo firma y lo entrega
 * al `Api::sendXml()` de siempre, que es el mismo canal REST (OAuth2 + ticket) que ya usa la
 * guia del remitente. Cualquier otro documento cae en `parent::send()` sin tocarse.
 *
 * `$signer`, `$lastXml` y `$options` son privados en `Api`, asi que se duplican aca capturandolos
 * en los setters que el contenedor ya llama (`setCertificate`, `setBuilderOptions`).
 */
class CarrierApi extends Api
{
    private ?string $certificate = null;
    private array $builderOptions = [];
    private ?string $lastCarrierXml = null;

    public function setCertificate(string $certificate): Api
    {
        $this->certificate = $certificate;

        return parent::setCertificate($certificate);
    }

    public function setBuilderOptions(array $options): Api
    {
        $this->builderOptions = array_merge($this->builderOptions, $options);

        return parent::setBuilderOptions($options);
    }

    public function send(DocumentInterface $document): ?BaseResult
    {
        if (!$document instanceof DespatchCarrier) {
            $this->lastCarrierXml = null;

            return parent::send($document);
        }

        $this->lastCarrierXml = $this->getXmlSigned($document);

        return $this->sendXml($document->getName(), $this->lastCarrierXml);
    }

    public function getLastXml(): ?string
    {
        return $this->lastCarrierXml ?? parent::getLastXml();
    }

    /**
     * Arma y firma el XML de la guia del transportista, sin enviarlo — lo que necesita
     * `despatch/xml`.
     */
    public function getXmlSigned(DespatchCarrier $document): string
    {
        $builder = new DespatchCarrierBuilder($this->builderOptions);
        $xml = $builder->build($document);

        $signer = new SignedXml();
        if ($this->certificate !== null) {
            $signer->setCertificate($this->certificate);
        }

        return $signer->signXml($xml);
    }
}
