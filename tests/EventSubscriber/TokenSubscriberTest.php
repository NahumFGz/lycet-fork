<?php
/**
 * El token de `/api/*`: por `Authorization: Bearer` y, mientras se despliegan los clientes nuevos,
 * todavia por `?token=`. Sin pasar por HTTP, para cubrir cada forma del header.
 */

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\TokenSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class TokenSubscriberTest extends TestCase
{
    /**
     * @dataProvider acceptedProvider
     */
    public function testAccepted(string $uri, array $headers): void
    {
        (new TokenSubscriber('123456'))->onKernelRequest($this->event($uri, $headers));

        $this->addToAssertionCount(1);
    }

    public function acceptedProvider(): array
    {
        return [
            'header' => ['/api/v1/invoice/send', ['HTTP_AUTHORIZATION' => 'Bearer 123456']],
            'header en minusculas' => ['/api/v1/invoice/send', ['HTTP_AUTHORIZATION' => 'bearer 123456']],
            'query, cliente viejo' => ['/api/v1/invoice/send?token=123456', []],
            'fuera de /api' => ['/', []],
        ];
    }

    /**
     * @dataProvider deniedProvider
     */
    public function testDenied(string $uri, array $headers): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        (new TokenSubscriber('123456'))->onKernelRequest($this->event($uri, $headers));
    }

    public function deniedProvider(): array
    {
        return [
            'sin token' => ['/api/v1/invoice/send', []],
            'header con otro token' => ['/api/v1/invoice/send', ['HTTP_AUTHORIZATION' => 'Bearer 654321']],
            'header con el token de prefijo' => ['/api/v1/invoice/send', ['HTTP_AUTHORIZATION' => 'Bearer 12345']],
            'header sin Bearer' => ['/api/v1/invoice/send', ['HTTP_AUTHORIZATION' => '123456']],
            'header con algo antes' => ['/api/v1/invoice/send', ['HTTP_AUTHORIZATION' => 'Token Bearer 123456']],
            'header Basic' => ['/api/v1/invoice/send', ['HTTP_AUTHORIZATION' => 'Basic 123456']],
            'header con algo despues' => ['/api/v1/invoice/send', ['HTTP_AUTHORIZATION' => 'Bearer 123456 otro']],
            'header Bearer vacio' => ['/api/v1/invoice/send', ['HTTP_AUTHORIZATION' => 'Bearer ']],
            // Un header presente manda: no se cae a la query si el header no sirve.
            'header malo y query buena' => [
                '/api/v1/invoice/send?token=123456',
                ['HTTP_AUTHORIZATION' => 'Bearer otro'],
            ],
            'header Basic y query buena' => [
                '/api/v1/invoice/send?token=123456',
                ['HTTP_AUTHORIZATION' => 'Basic 123456'],
            ],
            'query con otro token' => ['/api/v1/invoice/send?token=otro', []],
            'query como arreglo' => ['/api/v1/invoice/send?token[]=123456', []],
        ];
    }

    public function testEmptyConfiguredTokenDeniesEverything(): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        (new TokenSubscriber(''))->onKernelRequest($this->event('/api/v1/invoice/send?token=', []));
    }

    public function testSubRequestIsNotChecked(): void
    {
        $event = new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create('/api/v1/invoice/send', 'POST'),
            HttpKernelInterface::SUB_REQUEST
        );

        (new TokenSubscriber('123456'))->onKernelRequest($event);

        $this->addToAssertionCount(1);
    }

    private function event(string $uri, array $server): RequestEvent
    {
        return new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create($uri, 'POST', [], [], [], $server),
            HttpKernelInterface::MAIN_REQUEST
        );
    }
}
