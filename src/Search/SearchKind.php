<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Search;

/**
 * The three groups of the global search (1.18): workspaces and services by name, endpoints by name, path and HTTP
 * method. The value is the group key (`api_studio_<value>`) and the `search.groups` option.
 */
enum SearchKind: string
{
    case Workspace = 'workspaces';
    case Service   = 'services';
    case Endpoint  = 'endpoints';
}
