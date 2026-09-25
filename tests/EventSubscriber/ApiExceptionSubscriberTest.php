<?php
/**
 * La red de seguridad para los `\Error` de PHP, sin pasar por HTTP: por los endpoints no hay un
 * camino estable que lo dispare (los que habia se validan ahora con un 400).
 */

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\ApiExceptionSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class ApiExceptionSubscriberTest extends TestCase
{
    public function testErrorInApiControllerBecomesAnException(): void
    {
        $previous = new \TypeError('Call to a member function getRuc() on null');
        $event = $this->argumentsEvent('/api/v1/invoice/send', static function () use ($previous) {
            throw $previous;
        });

        (new ApiExceptionSubscriber())->wrapController($event);

        try {
            ($event->getController())();
            $this->fail('El \Error tenia que convertirse en excepcion');
        } catch (\RuntimeException $e) {
            // Una excepcion comun si la atrapa HttpKernel::handle(); un \Error no.
            $this->assertSame($previous, $e->getPrevious());
            $this->assertEquals($previous->getMessage(), $e->getMessage());
        }
    }

    public function testControllerStillReceivesItsArguments(): void
    {
        $event = $this->argumentsEvent('/api/v1/invoice/send', static function (string $a, string $b) {
            return $a . $b;
        });

        (new ApiExceptionSubscriber())->wrapController($event);

        $this->assertEquals('ab', ($event->getController())(...$event->getArguments()));
    }

    public function testOutsideApiControllerIsNotWrapped(): void
    {
        $controller = static function () {
        };
        $event = $this->argumentsEvent('/', $controller);

        (new ApiExceptionSubscriber())->wrapController($event);

        $this->assertSame($controller, $event->getController());
    }

    private function argumentsEvent(string $path, callable $controller): ControllerArgumentsEvent
    {
        return new ControllerArgumentsEvent(
            $this->createMock(HttpKernelInterface::class),
            $controller,
            ['a', 'b'],
            Request::create($path, 'POST'),
            HttpKernelInterface::MAIN_REQUEST
        );
    }
}
