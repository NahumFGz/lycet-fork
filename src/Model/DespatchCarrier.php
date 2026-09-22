<?php

declare(strict_types=1);

namespace App\Model;

use Greenter\Model\Client\Client;
use Greenter\Model\Despatch\Despatch;

/**
 * Guia de Remision Electronica del TRANSPORTISTA (tipoDoc 31).
 *
 * greenter solo modela la guia del REMITENTE (tipoDoc 09, `Greenter\Model\Despatch\Despatch`),
 * donde el emisor ES el remitente. En la guia del transportista el emisor es quien traslada la
 * carga y el remitente es un tercero, asi que hace falta un campo mas: `remitente`.
 *
 * Todo lo demas se hereda de `Despatch` sin cambios — mismo `company`, `destinatario`, `envio`
 * (vehiculo, choferes, partida, llegada), `details` y `addDocs`. El XML lo arma
 * `App\Xml\Builder\DespatchCarrierBuilder` con su propia plantilla; `App\Greenter\CarrierApi` es
 * quien lo enruta ahi en vez de al builder de greenter.
 *
 * `tercero` (heredado) NO se usa aca: en la 09 es el `SellerSupplierParty` de una venta por
 * tercero, que no aplica a la guia del transportista.
 */
class DespatchCarrier extends Despatch
{
    /**
     * Remitente: quien encarga el traslado. En el XML va como
     * `cac:Delivery/cac:Despatch/cac:DespatchParty`.
     *
     * @var Client|null
     */
    private $remitente;

    public function getRemitente(): ?Client
    {
        return $this->remitente;
    }

    public function setRemitente(?Client $remitente): DespatchCarrier
    {
        $this->remitente = $remitente;

        return $this;
    }
}
