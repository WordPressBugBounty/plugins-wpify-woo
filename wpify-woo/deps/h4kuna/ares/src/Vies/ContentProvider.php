<?php

declare (strict_types=1);
namespace WpifyWooDeps\h4kuna\Ares\Vies;

use WpifyWooDeps\h4kuna\Ares\Exceptions\ServerResponseException;
use WpifyWooDeps\Nette\Utils\Strings;
use stdClass;
/**
 * @phpstan-import-type ViesResponse from Client
 */
final class ContentProvider
{
    public function __construct(private Client $client)
    {
    }
    /**
     * @param string|ViesEntity $vatNumber
     * @return ViesResponse
     *
     * @throws ServerResponseException
     */
    public function checkVat(string|ViesEntity $vatNumber) : object
    {
        if (\is_string($vatNumber)) {
            $match = Strings::match($vatNumber, '/(?<country>[A-Z]+)(?<number>\\d+)/');
            if (isset($match['country'], $match['number'])) {
                ['country' => $country, 'number' => $number] = $match;
            } else {
                $country = '';
                $number = $vatNumber;
            }
            $viesEntity = new ViesEntity($number, $country);
        } else {
            $viesEntity = $vatNumber;
        }
        return $this->client->checkVatNumber($viesEntity);
    }
    public function status() : stdClass
    {
        return $this->client->status();
    }
}
