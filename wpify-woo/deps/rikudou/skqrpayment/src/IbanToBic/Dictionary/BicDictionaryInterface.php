<?php

namespace WpifyWooDeps\rikudou\SkQrPayment\IbanToBic\Dictionary;

use WpifyWooDeps\Rikudou\Iban\Iban\IbanInterface;
use WpifyWooDeps\rikudou\SkQrPayment\Exception\BicNotFoundException;
interface BicDictionaryInterface
{
    /**
     * Returns the two-letter country code this dictionary is relevant to
     */
    public function getCountryCode() : string;
    /**
     * Returns the BIC, throws BicNotFoundException if the BIC could not be found
     *
     * @throws BicNotFoundException
     */
    public function getBic(IbanInterface $iban) : string;
}
