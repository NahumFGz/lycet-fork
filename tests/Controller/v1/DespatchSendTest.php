<?php
/**
 * `/despatch/send` cuando SUNAT no responde: tiene que devolver el XML firmado y su hash, como el
 * resto de comprobantes, y no confundir un fallo propio (armado, firma) con una caida de SUNAT.
 *
 * SUNAT "caida" es un puerto local cerrado: rechaza la conexion al instante, sin red, en el mismo
 * punto donde fallaria contra SUNAT (el OAuth2 de `Api::sendXml()`).
 */

namespace App\Tests\Controller\v1;

use App\Greenter\CarrierApi;
use App\Service\ConfigProviderInterface;
use Greenter\Api;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class DespatchSendTest extends WebTestCase
{
    private const SUNAT_CAIDA = 'http://127.0.0.1:9';

    private string $certificate;

    /**
     * @dataProvider guidesProvider
     */
    public function testSunatDownStillReturnsSignedXmlAndHash(string $fixture, string $tipoDoc): void
    {
        $client = $this->clientWithSunatDown();

        $response = $this->send($client, $fixture);

        $this->assertEquals(200, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        $this->assertFalse($body['sunatResponse']['success']);
        $this->assertEquals('HTTP', $body['sunatResponse']['error']['code']);

        $xpath = $this->xpath($body['xml']);
        $this->assertEquals($tipoDoc, $xpath->evaluate('string(//cbc:DespatchAdviceTypeCode)'));
        $this->assertEquals($xpath->evaluate('string(//ds:DigestValue)'), $body['hash']);
    }

    public function guidesProvider(): array
    {
        return [
            'remitente (09)' => ['despatch.json', '09'],
            'transportista (31)' => ['despatch-carrier.json', '31'],
        ];
    }

    /**
     * Dos envios en el mismo kernel, como en un worker de php-pm (el Api es un servicio
     * compartido): si el segundo no se puede firmar, no es una caida de SUNAT ni puede devolver
     * el XML del primero.
     */
    public function testUnsignableGuideIsNotReportedAsSunatDown(): void
    {
        $client = $this->clientWithSunatDown();
        $client->disableReboot();

        $first = json_decode($this->send($client, 'despatch-carrier.json')->getContent(), true);
        $this->assertNotEmpty($first['xml']);

        $this->certificate = 'no es un certificado';
        $response = $this->send($client, 'despatch-carrier.json');

        $this->assertEquals(500, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('message', $body);
        $this->assertArrayNotHasKey('xml', $body);
        $this->assertArrayNotHasKey('sunatResponse', $body);
    }

    private function clientWithSunatDown(): KernelBrowser
    {
        $this->certificate = file_get_contents(__DIR__ . '/../../Resources/cert.pem');

        $config = $this->getMockBuilder(ConfigProviderInterface::class)->getMock();
        $config->method('get')->willReturnCallback(function ($key) {
            return $key === 'certificate' ? $this->certificate : '';
        });

        $client = static::createClient();
        $client->getContainer()->set(ConfigProviderInterface::class, $config);
        $client->getContainer()->set(Api::class, new CarrierApi([
            'auth' => self::SUNAT_CAIDA,
            'cpe' => self::SUNAT_CAIDA,
        ]));

        return $client;
    }

    private function send(KernelBrowser $client, string $fixture)
    {
        $client->request(
            'POST',
            '/api/v1/despatch/send?token=123456',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            file_get_contents(__DIR__ . '/../../Resources/documents/' . $fixture)
        );

        return $client->getResponse();
    }

    private function xpath(string $xml): \DOMXPath
    {
        $doc = new \DOMDocument();
        $doc->loadXML($xml);

        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');

        return $xpath;
    }
}
