<?php
/**
 * Created by PhpStorm.
 * User: Giansalex
 * Date: 17/02/2018
 * Time: 23:50
 */

namespace App\Controller\v1;

use App\Greenter\CarrierApi;
use App\Model\DespatchCarrier;
use App\Service\DocumentRequestInterface;
use App\Service\SeeApiFactory;
use Greenter\Model\Despatch\Despatch;
use Greenter\Model\DocumentInterface;
use Greenter\Model\Response\Error;
use Greenter\Model\Response\StatusResult;
use Greenter\Model\Response\SummaryResult;
use Greenter\Report\XmlUtils;
use JMS\Serializer\SerializerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Class DespatchController.
 *
 * @Route("/api/v1/despatch")
 */
class DespatchController extends AbstractController
{
    private DocumentRequestInterface $document;
    private SerializerInterface $serializer;

    /**
     * @param DocumentRequestInterface $document
     * @param SerializerInterface $serializer
     */
    public function __construct(DocumentRequestInterface $document, SerializerInterface $serializer)
    {
        $this->document = $document;
        $this->serializer = $serializer;
    }

    /**
     * @Route("/send", methods={"POST"})
     *
     * @return Response
     */
    public function send(Request $request, SeeApiFactory $factory, XmlUtils $xmlUtils): Response
    {
        /** @var \Greenter\Model\Despatch\Despatch $document */
        $document = $this->document->getDocument($this->documentClass($request));

        if ($error = $this->validateCarrier($document)) {
            return $error;
        }

        $see = $factory->build($document->getCompany()->getRuc());

        try {
            $result = $see->send($document);
        } catch (\Throwable $e) {
            // Sin XML firmado, lo que fallo fue armar o firmar la guia, no la conexion con SUNAT:
            // reportarlo como "HTTP" invitaria a reintentar algo que nunca va a salir.
            if ($see->getLastXml() === null) {
                throw $e;
            }
            $result = (new SummaryResult())->setError(new Error('HTTP', $e->getMessage()));
        }

        // Firmado antes de salir a la red: el XML y su hash estan aunque SUNAT no haya respondido,
        // con la misma forma que el resto de comprobantes (DocumentRequest::send()).
        $xml = $see->getLastXml();

        $data = [
            'xml' => $xml,
            'hash' => $xmlUtils->getHashSign($xml),
            'sunatResponse' => $result
        ];

        $json = $this->serializer->serialize($data, 'json');

        return new JsonResponse($json, 200, [], true);
    }

    /**
     * @Route("/xml", methods={"POST"})
     *
     * @return Response
     */
    public function xml(Request $request, SeeApiFactory $factory): Response
    {
        if ($this->documentClass($request) !== DespatchCarrier::class) {
            return $this->document->xml(Despatch::class);
        }

        // La guia del transportista tiene su propia plantilla, que no conoce el builder de
        // greenter al que llega `DocumentRequest::xml()` — se arma y firma por CarrierApi.
        /** @var DespatchCarrier $document */
        $document = $this->document->getDocument(DespatchCarrier::class);

        if ($error = $this->validateCarrier($document)) {
            return $error;
        }

        $see = $factory->build($document->getCompany()->getRuc());

        if (!$see instanceof CarrierApi) {
            return new JsonResponse(['message' => 'Guia de transportista no soportada'], 500);
        }

        $response = new Response($see->getXmlSigned($document));
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $document->getName() . '.xml'
        ));
        $response->headers->set('Content-Type', 'text/xml');

        return $response;
    }

    /**
     * @Route("/pdf", methods={"POST"})
     *
     * @return Response
     */
    public function pdf(): Response
    {
        return $this->document->pdf(Despatch::class);
    }

    /**
     * Un `remitente` vacio pasaria de largo — la plantilla renderiza los campos sin el, y el XML
     * sale firmado pero sin remitente. SUNAT lo rechazaria, y un rechazo de guia inmoviliza el
     * vehiculo, asi que se corta aca con un 400 en vez de mandarlo.
     */
    private function validateCarrier(DocumentInterface $document): ?JsonResponse
    {
        if (!$document instanceof DespatchCarrier) {
            return null;
        }

        if ($document->getRemitente() === null) {
            return new JsonResponse(
                ['message' => 'remitente es requerido para la guia del transportista (tipoDoc 31)'],
                400
            );
        }

        return null;
    }

    /**
     * La guia del REMITENTE (09) y la del TRANSPORTISTA (31) comparten endpoint y casi todo el
     * cuerpo; se distinguen por `tipoDoc`. La 31 necesita un campo mas (`remitente`) y otra
     * plantilla XML — ver App\Model\DespatchCarrier.
     */
    private function documentClass(Request $request): string
    {
        $data = json_decode($request->getContent(), true);
        // Mismo desenvoltorio que DocumentRequestParser: el cuerpo puede venir dentro de
        // `document` o plano.
        $document = $data['document'] ?? $data;

        return is_array($document) && ($document['tipoDoc'] ?? null) === '31'
            ? DespatchCarrier::class
            : Despatch::class;
    }

    /**
     * @Route("/status", methods={"GET"})
     *
     * @param Request $request
     * @param SeeApiFactory $factory
     * @return JsonResponse
     */
    public function status(Request $request, SeeApiFactory $factory): JsonResponse
    {
        $ticket = $request->query->get('ticket');
        if (empty($ticket)) {
            return new JsonResponse(['message' => 'Ticket Requerido'], 400);
        }
        $see = $factory->build($request->query->get('ruc'));

        try {
            $result = $see->getStatus($ticket);

            if ($result->isSuccess()) {
                $result->setCdrZip(base64_encode($result->getCdrZip()));
            }
        } catch (\Throwable $e) {
            $result = (new StatusResult())->setError(new Error('HTTP', $e->getMessage()));
        }

        $json = $this->serializer->serialize($result, 'json');

        return new JsonResponse($json, 200, [], true);
    }
}