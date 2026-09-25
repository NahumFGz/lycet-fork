<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Los errores de `/api/*` salen como JSON (`{"message": ...}`) con su status real, en vez de la
 * pagina de error de Symfony o, peor, un 502 de php-pm con el worker reiniciado.
 *
 * Cierra dos huecos, los dos propios de correr bajo php-pm:
 *
 *  - Symfony 5.4 arma la pagina de error con una sub-peticion (`ErrorListener::onKernelException`).
 *    Si esa sub-peticion falla, la excepcion escapa del kernel y php-pm responde 502. Respondiendo
 *    aca antes (prioridad -64) se corta la propagacion y la sub-peticion no ocurre; el log de
 *    `ErrorListener::logKernelException` (prioridad 0) se conserva.
 *  - `HttpKernel::handle()` solo atrapa `\Exception`: un `\Error` de PHP (un `TypeError`, un
 *    metodo llamado sobre null) sale del kernel sin respuesta y php-pm corta la conexion. Por eso
 *    el controlador se envuelve para convertir el `\Error` en una excepcion comun, que el kernel
 *    si maneja y termina aca como 500.
 */
class ApiExceptionSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            // Ultimo, para envolver el controlador que de verdad se va a ejecutar.
            KernelEvents::CONTROLLER_ARGUMENTS => ['wrapController', -1024],
            KernelEvents::EXCEPTION => ['onKernelException', -64],
        ];
    }

    public function wrapController(ControllerArgumentsEvent $event): void
    {
        if (!$this->isApi($event->getRequest())) {
            return;
        }

        $controller = $event->getController();
        $event->setController(static function (...$arguments) use ($controller) {
            try {
                return $controller(...$arguments);
            } catch (\Error $e) {
                throw new \RuntimeException($e->getMessage(), 0, $e);
            }
        });
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        if (!$this->isApi($event->getRequest())) {
            return;
        }

        $throwable = $event->getThrowable();
        $isHttp = $throwable instanceof HttpExceptionInterface;

        $event->setResponse(new JsonResponse(
            ['message' => $throwable->getMessage()],
            $isHttp ? $throwable->getStatusCode() : 500,
            $isHttp ? $throwable->getHeaders() : []
        ));
    }

    private function isApi(Request $request): bool
    {
        return strncmp($request->getPathInfo(), '/api', 4) === 0;
    }
}
