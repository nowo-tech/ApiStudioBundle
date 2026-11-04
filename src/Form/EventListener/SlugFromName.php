<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Form\EventListener;

use Nowo\ApiStudioBundle\Service\ImportExport\SlugHelper;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;

use function is_array;
use function is_string;
use function trim;

/**
 * An empty slug is derived from the submitted name (1.11): lowercase, other characters as `_`.
 */
final class SlugFromName
{
    /**
     * @param FormBuilderInterface<mixed> $builder
     */
    public static function attach(FormBuilderInterface $builder): void
    {
        $builder->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event): void {
            $data = $event->getData();
            if (!is_array($data)) {
                return;
            }

            $slug = $data['slug'] ?? '';
            $name = $data['name'] ?? '';
            if ((is_string($slug) && trim($slug) !== '') || !is_string($name) || trim($name) === '') {
                return;
            }

            $data['slug'] = SlugHelper::fromString($name, '');
            $event->setData($data);
        });
    }
}
