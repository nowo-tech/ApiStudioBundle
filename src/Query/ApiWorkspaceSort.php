<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Query;

/**
 * 1.19 (DDD-host checklist B7): the order of {@see ApiWorkspaceQueryInterface::find()}. Every order ends with the
 * workspace id, so a page never repeats or skips a row between two calls on unchanged data.
 */
enum ApiWorkspaceSort: string
{
    case Name      = 'name';
    case CreatedAt = 'created_at';
    case UpdatedAt = 'updated_at';
    case Id        = 'id';
}
