<?php

declare(strict_types=1);

namespace App\Xml\Builder;

use Greenter\Builder\BuilderInterface;
use Greenter\Model\DocumentInterface;
use Greenter\Model\TimeZonePe;
use Greenter\Xml\Builder\TwigBuilder;
use Greenter\Xml\Filter\FormatFilter;
use Twig\Environment;
use Twig\Extension\CoreExtension;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;

/**
 * Arma el XML de la Guia de Remision del TRANSPORTISTA (tipoDoc 31).
 *
 * greenter resuelve el builder por el nombre corto del modelo
 * (`Greenter\Factory\XmlBuilderResolver`), y sus plantillas viven en un directorio fijo dentro de
 * `greenter/xml` — no hay forma de agregarle una sin forkear el paquete. Por eso este builder
 * hereda de `TwigBuilder` solo para reusar `render()`, pero arma su propio entorno Twig apuntando
 * a `src/Xml/Templates/` de este repo. `App\Greenter\CarrierApi` es quien lo usa.
 *
 * El entorno replica el de `TwigBuilder::createTwig()` (privado, por eso no se puede reusar):
 * mismos filtros `n_format`/`n_format_limit` y misma zona horaria, o los montos y fechas salen
 * distinto que en el resto de los comprobantes.
 */
class DespatchCarrierBuilder extends TwigBuilder implements BuilderInterface
{
    public const TEMPLATE = 'despatchCarrier.xml.twig';

    /**
     * @param array $options Opciones de Twig (las mismas que recibe greenter via
     *                       `Api::setBuilderOptions()`, p.ej. `cache`). `autoescape` se fuerza a
     *                       false: es XML, no HTML.
     */
    public function __construct(array $options = [])
    {
        // Sin parent::__construct() a proposito: cargaria las plantillas de greenter/xml.
        $this->twig = $this->createTwigForApp($options);
    }

    public function build(DocumentInterface $document): ?string
    {
        return $this->render(self::TEMPLATE, $document);
    }

    private function createTwigForApp(array $options): Environment
    {
        $loader = new FilesystemLoader(__DIR__ . '/../Templates');
        $twig = new Environment($loader, array_merge(['autoescape' => false], $options));

        $formatFilter = new FormatFilter();
        $twig->addFilter(new TwigFilter('n_format', [$formatFilter, 'number']));
        $twig->addFilter(new TwigFilter('n_format_limit', [$formatFilter, 'numberLimit']));

        $extension = $twig->getExtension(CoreExtension::class);
        if ($extension instanceof CoreExtension) {
            $extension->setTimezone(TimeZonePe::DEFAULT);
        }

        return $twig;
    }
}
