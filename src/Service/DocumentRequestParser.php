<?php
/**
 * Created by PhpStorm.
 * User: Giansalex
 * Date: 13/02/2018
 * Time: 22:17
 */

namespace App\Service;

use Greenter\Model\DocumentInterface;
use JMS\Serializer\Exception\Exception as SerializerException;
use JMS\Serializer\SerializerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class DocumentRequestParser implements RequestParserInterface
{
    /**
     * @var SerializerInterface
     */
    private $serializer;

    /**
     * DocumentRequestParser constructor.
     * @param SerializerInterface $serializer
     */
    public function __construct(SerializerInterface $serializer)
    {
        $this->serializer = $serializer;
    }

    /**
     * @param Request $request
     * @param string $class
     * @return mixed
     */
    function getObject(Request $request, string $class): DocumentInterface
    {
        $data = $request->getContent();

        $dataJson = $this->decode($request);
        if(array_key_exists('document', $dataJson)) {
            $data = json_encode($dataJson['document']);
        }

        // Un campo con el tipo equivocado (`details` como texto, por ejemplo) es un error del
        // cuerpo, no del servidor: JMS lo reporta con su propia excepcion, o con un TypeError si
        // la propiedad del modelo de greenter esta tipada.
        try {
            return $this->serializer->deserialize(
                $data,
                $class,
                'json'
            );
        } catch (SerializerException | \TypeError $e) {
            throw new BadRequestHttpException('El documento no tiene el formato esperado: ' . $e->getMessage(), $e);
        }
    }

    /**
     * @param Request $request
     * @param string $key
     * @return mixed
     */
    function getKey(Request $request, string $key): ?Array
    {
        $data = $this->decode($request);

        return array_key_exists($key, $data) ? $data[$key] : null;
    }

    /**
     * Sin esto, un cuerpo vacio o un JSON roto llegaba como null a `array_key_exists()` y el
     * TypeError tumbaba el worker de php-pm (502 sin mensaje).
     */
    private function decode(Request $request): array
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            throw new BadRequestHttpException('El cuerpo tiene que ser un objeto JSON');
        }

        return $data;
    }
}