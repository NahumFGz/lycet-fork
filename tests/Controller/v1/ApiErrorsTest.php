<?php
/**
 * Errores de `/api/*`: siempre JSON con el status que corresponde.
 *
 * Bajo php-pm (la imagen de produccion), cada uno de estos casos respondia 502 y reiniciaba el
 * worker: el token invalido porque la sub-peticion de la pagina de error tambien pasaba por
 * TokenSubscriber, y el resto porque terminaba en un `\Error` de PHP que HttpKernel no atrapa.
 * Ver App\EventSubscriber\ApiExceptionSubscriber.
 */

namespace App\Tests\Controller\v1;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ApiErrorsTest extends WebTestCase
{
    public function testInvalidTokenIsForbiddenJson(): void
    {
        $response = $this->post('/api/v1/invoice/send?token=otro', '{}');

        $this->assertEquals(403, $response['status']);
        $this->assertEquals('This action needs a valid token!', $response['body']['message']);
    }

    /**
     * @dataProvider notAnObjectProvider
     */
    public function testBodyThatIsNotAJsonObjectIsBadRequest(string $content): void
    {
        $response = $this->post('/api/v1/invoice/send?token=123456', $content);

        $this->assertEquals(400, $response['status']);
        $this->assertEquals('El cuerpo tiene que ser un objeto JSON', $response['body']['message']);
    }

    public function notAnObjectProvider(): array
    {
        return [
            'vacio' => [''],
            'json roto' => ['{"tipoDoc":'],
            'texto' => ['"factura"'],
        ];
    }

    /**
     * @dataProvider withoutCompanyProvider
     */
    public function testDocumentWithoutCompanyRucIsBadRequest(string $path, array $document): void
    {
        $response = $this->post("/api/v1/$path?token=123456", json_encode($document));

        $this->assertEquals(400, $response['status']);
        $this->assertEquals('company.ruc es requerido', $response['body']['message']);
    }

    public function withoutCompanyProvider(): array
    {
        return [
            'factura sin company' => ['invoice/send', ['tipoDoc' => '01', 'serie' => 'F001', 'correlativo' => '1']],
            'factura con company sin ruc' => ['invoice/send', ['tipoDoc' => '01', 'company' => ['razonSocial' => 'X']]],
            'xml de factura sin company' => ['invoice/xml', ['tipoDoc' => '01']],
            'resumen sin company' => ['summary/send', ['correlativo' => '1']],
            'guia del transportista sin company' => ['despatch/send', [
                'tipoDoc' => '31',
                'remitente' => ['tipoDoc' => '1', 'numDoc' => '45678912', 'rznSocial' => 'MARIA QUISPE ROJAS'],
            ]],
        ];
    }

    public function testFieldWithWrongTypeIsBadRequest(): void
    {
        $document = json_decode(file_get_contents(__DIR__ . '/../../Resources/documents/invoice.json'), true);
        $document['details'] = 'no es una lista';

        $response = $this->post('/api/v1/invoice/send?token=123456', json_encode($document));

        $this->assertEquals(400, $response['status']);
        $this->assertStringStartsWith('El documento no tiene el formato esperado', $response['body']['message']);
    }

    /**
     * @return array{status: int, body: array}
     */
    private function post(string $uri, string $content): array
    {
        $client = static::createClient();
        $client->request('POST', $uri, [], [], ['CONTENT_TYPE' => 'application/json'], $content);
        $response = $client->getResponse();

        $this->assertStringContainsString('application/json', $response->headers->get('Content-Type'));

        return [
            'status' => $response->getStatusCode(),
            'body' => json_decode($response->getContent(), true),
        ];
    }
}
