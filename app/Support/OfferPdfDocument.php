<?php

namespace App\Support;

use setasign\Fpdi\Tfpdf\Fpdi;

class OfferPdfDocument extends Fpdi
{
    public function __construct()
    {
        parent::__construct('P', 'pt');
        $this->fontpath = resource_path('fonts/');
        $this->cMargin = 0;
    }
}
