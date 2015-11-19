<?php
namespace ExtDirect;

interface ActionInterface
{
    /**
     * @return array
     */
    public function run();

    /**
     * @return boolean
     */
    public function isFormHandler();

    /**
     * @return boolean
     */
    public function isUpload();
}