<?php

return [
    "name" => 'Weline_CKEditorEditorManager',
    "version" => '1.0.0',
    "requires" => [
        'Weline_Backend' => '*',
        'Weline_EditorManager' => '*',
    ],
    "optional" => [
    ],
    "provides" => [
        'editor_manager.Weline_CKEditorEditorManager' => \Weline\CKEditorEditorManager\EditorManager\CKEditor::class,
    ],
];
