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
 * `$signer` y `$options` son privados en `Api`, asi que se duplican aca capturandolos en los
 * setters que el contenedor ya llama (`setCertificate`, `setBuilderOptions`). El ultimo XML
 * enviado tambien se lleva aca (`sendXml()`), para las dos guias.
 */
class CarrierApi extends Api
{
    private ?string $certificate = null;
    private array $builderOptions = [];
    private ?string $lastSentXml = null;

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
        // Esta instancia es un servicio compartido y el worker de php-pm la reusa entre
        // peticiones: si el armado o la firma fallan, getLastXml() no puede devolver el XML de
        // una guia anterior.
        $this->lastSentXml = null;

        if (!$document instanceof DespatchCarrier) {
            return parent::send($document);
        }

        return $this->sendXml($document->getName(), $this->getXmlSigned($document));
    }

    /**
     * Guarda el XML firmado antes de salir a la red (el OAuth2 y el envio van dentro de
     * `parent::sendXml()`), asi que queda disponible aunque SUNAT no responda. `parent::send()`
     * tambien pasa por aca con el XML que armo greenter, de modo que vale para las dos guias.
     */
    public function sendXml(string $name, string $content): ?BaseResult
    {
        $this->lastSentXml = $content;

        return parent::sendXml($name, $content);
    }

    public function getLastXml(): ?string
    {
        return $this->lastSentXml;
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
