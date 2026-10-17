<?php
/**
 * Created by PhpStorm.
 * User: Giansalex
 * Date: 17/02/2018
 * Time: 20:02
 */

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

class TokenSubscriber implements EventSubscriberInterface
{
    /**
     * @var string
     */
    private $token;

    public function __construct($token)
    {
        $this->token = $token;
    }

    public function onKernelRequest(RequestEvent  $event)
    {
        // Solo la peticion del cliente: las sub-peticiones internas de Symfony (la de la pagina de
        // error, por ejemplo) no traen el token, y rechazarlas convertia cualquier error en un 502.
        if (!$event->isMainRequest()) {
            return;
        }

        $path = $event->getRequest()->getPathInfo();

        $isApi = substr($path, 0, 4) === "/api";

        if (!$isApi) {
            return;
        }

        if ($event->getRequest()->getMethod() === 'OPTION') {
            return;
        }

        if (!$this->isValid($this->tokenOf($event->getRequest()))) {
            throw new AccessDeniedHttpException('This action needs a valid token!');
        }
    }

    /**
     * El token va en `Authorization: Bearer <token>`: en la URL quedaba escrito en los access logs.
     * Sin ese header se acepta todavia `?token=`, para que un cliente viejo siga andando mientras
     * se despliega el nuevo; un header presente manda, aunque traiga otra cosa.
     */
    private function tokenOf(Request $request): ?string
    {
        $header = $request->headers->get('Authorization');
        if ($header !== null) {
            return preg_match('/^Bearer\s+(\S+)\s*$/i', $header, $match) === 1 ? $match[1] : null;
        }

        // all() y no get(): un `?token[]=` es un arreglo, y get() lo rechaza con una deprecacion.
        $token = $request->query->all()['token'] ?? null;

        return is_string($token) ? $token : null;
    }

    private function isValid(?string $token): bool
    {
        // hash_equals compara en tiempo constante: `!==` cortaba en el primer caracter distinto.
        return $token !== null && (string) $this->token !== '' && hash_equals((string) $this->token, $token);
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => 'onKernelRequest',
        ];
    }
}
