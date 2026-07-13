<?php

namespace Weline\FileManager\Api;

use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Http\Request;

abstract class FileManager extends DataObject implements \Weline\FileManager\FileManagerInterface
{
    protected Request $request;

    function __construct(Request $request, array $data = [])
    {
        parent::__construct($data);
        $this->request = $request;
    }

    use  FileManagerTrait;
}
