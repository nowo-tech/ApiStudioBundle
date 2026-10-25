<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Twig;

use Nowo\ApiStudioBundle\Form\ClientSideFieldsType;
use Nowo\ApiStudioBundle\Twig\ApiStudioFormsExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Forms;
use Twig\TwigFunction;

final class ApiStudioFormsExtensionTest extends TestCase
{
    public function testRegistersFunctions(): void
    {
        $names = array_map(static fn (TwigFunction $f): string => $f->getName(), (new ApiStudioFormsExtension(Forms::createFormFactory()))->getFunctions());

        self::assertSame(['api_studio_csrf_form', 'api_studio_tree_filter_form', 'api_studio_service_modal_forms'], $names);
    }

    public function testTreeFilterKeepsId(): void
    {
        $view = (new ApiStudioFormsExtension(Forms::createFormFactory()))->treeFilterForm();

        self::assertSame('as-tree-filter', $view->children['search']->vars['id']);
        self::assertSame('sidebar.filter', $view->children['search']->vars['attr']['placeholder']);
    }

    public function testClientSideFieldsAreUnmappedAndOptional(): void
    {
        $form = Forms::createFormFactory()->createNamed('', ClientSideFieldsType::class, null, [
            'fields' => ['as-export-format' => ['Symfony\Component\Form\Extension\Core\Type\TextType', ['html_id' => 'x-id']]],
        ]);

        self::assertFalse($form->get('as-export-format')->getConfig()->getMapped());
        self::assertFalse($form->get('as-export-format')->isRequired());
        self::assertSame('x-id', $form->createView()->children['as-export-format']->vars['id']);
        self::assertSame('as-export-format', $form->createView()->children['as-export-format']->vars['full_name']);
    }
}
