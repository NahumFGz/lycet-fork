<?php
/**
 * Guia de Remision del TRANSPORTISTA (tipoDoc 31) — la que agrega este fork.
 *
 * Todo contra `/despatch/xml`, que firma localmente y no llama a SUNAT: estos tests corren sin
 * red. El envio real (`/despatch/send` -> ticket -> `/despatch/status` -> CDR) se verifica a mano
 * contra el sandbox GRE, ver la skill `lycet-fork`.
 *
 * Mismo patron WebTestCase + mock del ConfigProvider que InvoiceControllerTest.
 */

namespace App\Tests\Controller\v1;

use App\Service\ConfigProviderInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class DespatchCarrierControllerTest extends WebTestCase
{
    public function testXmlIsCarrierDespatchAdvice(): void
    {
        $xpath = $this->xmlFor('despatch-carrier.json');

        $this->assertEquals('31', $xpath->evaluate('string(/da:DespatchAdvice/cbc:DespatchAdviceTypeCode)'));
    }

    /**
     * El emisor de la guia del transportista es el propio transportista.
     */
    public function testEmisorIsTheCarrier(): void
    {
        $xpath = $this->xmlFor('despatch-carrier.json');

        $this->assertEquals(
            '20161515648',
            $xpath->evaluate('string(/da:DespatchAdvice/cac:DespatchSupplierParty/cac:Party/cac:PartyIdentification/cbc:ID)')
        );
    }

    /**
     * El remitente es un tercero y viaja en DespatchParty — es el campo que no existe en el
     * modelo de greenter y el motivo de todo este agregado.
     */
    public function testRemitenteGoesInDespatchParty(): void
    {
        $xpath = $this->xmlFor('despatch-carrier.json');
        $base = '/da:DespatchAdvice/cac:Shipment/cac:Delivery/cac:Despatch/cac:DespatchParty';

        $this->assertEquals('20384203133', $xpath->evaluate("string($base/cac:PartyIdentification/cbc:ID)"));
        $this->assertEquals(
            'ZV DISTRIBUIDORES S.A.C.',
            $xpath->evaluate("string($base/cac:PartyLegalEntity/cbc:RegistrationName)")
        );
    }

    /**
     * Motivo y modalidad de traslado son de la guia del remitente; en la del transportista SUNAT
     * no los espera.
     */
    public function testOmitsRemitenteOnlyElements(): void
    {
        $xpath = $this->xmlFor('despatch-carrier.json');

        $this->assertEquals(0, $xpath->query('//cbc:HandlingCode')->length);
        $this->assertEquals(0, $xpath->query('//cbc:TransportModeCode')->length);
        $this->assertEquals(0, $xpath->query('//cac:SellerSupplierParty')->length);
    }

    public function testCarrierXmlIsSigned(): void
    {
        $xpath = $this->xmlFor('despatch-carrier.json');

        $this->assertEquals(1, $xpath->query('//ds:SignatureValue')->length);
    }

    /**
     * Regresion: la guia del REMITENTE (09) sigue saliendo como antes — mismo endpoint, y es la
     * plantilla de greenter la que la arma, no la nuestra.
     */
    public function testRemitenteGuideIsUnchanged(): void
    {
        $xpath = $this->xmlFor('despatch.json');

        $this->assertEquals('09', $xpath->evaluate('string(/da:DespatchAdvice/cbc:DespatchAdviceTypeCode)'));
        $this->assertEquals(1, $xpath->query('//cbc:HandlingCode')->length);
        $this->assertEquals(1, $xpath->query('//cbc:TransportModeCode')->length);
        $this->assertEquals(0, $xpath->query('//cac:DespatchParty')->length);
    }

    /**
     * Sin remitente el XML saldria firmado pero invalido para SUNAT, asi que se corta antes.
     */
    public function testCarrierWithoutRemitenteIsRejected(): void
    {
        $data = json_decode(
            file_get_contents(__DIR__ . '/../../Resources/documents/despatch-carrier.json'),
            true
        );
        unset($data['remitente']);

        $client = $this->getClientConfigured();
        $client->request(
            'POST',
            '/api/v1/despatch/xml?token=123456',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($data)
        );

        $this->assertEquals(400, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('message', $body);
    }

    private function xmlFor(string $fixture): \DOMXPath
    {
        $data = file_get_contents(__DIR__ . '/../../Resources/documents/' . $fixture);

        $client = $this->getClientConfigured();
        $client->request(
            'POST',
            '/api/v1/despatch/xml?token=123456',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $data
        );

        $response = $client->getResponse();
        $this->assertEquals(200, $response->getStatusCode());

        $doc = new \DOMDocument();
        $doc->loadXML($response->getContent());
        $this->assertEquals('DespatchAdvice', $doc->documentElement->nodeName);

        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('da', 'urn:oasis:names:specification:ubl:schema:xsd:DespatchAdvice-2');
        $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');

        return $xpath;
    }

    private function getFileConfig(): ConfigProviderInterface
    {
        $stub = $this->getMockBuilder(ConfigProviderInterface::class)->getMock();

        $stub->method('get')
            ->willReturnCallback(function ($key) {
                switch ($key) {
                    case 'certificate':
                        return file_get_contents(__DIR__ . '/../../Resources/cert.pem');
                    default:
                        return '';
                }
            });

        /**@var $stub ConfigProviderInterface*/
        return $stub;
    }

    private function getClientConfigured()
    {
        $client = static::createClient();
        $client->getContainer()->set(ConfigProviderInterface::class, $this->getFileConfig());

        return $client;
    }
}
