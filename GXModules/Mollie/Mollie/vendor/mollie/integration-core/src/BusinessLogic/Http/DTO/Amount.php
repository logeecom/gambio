<?php

namespace Mollie\BusinessLogic\Http\DTO;

/**
 * Class Amount
 *
 * @package Mollie\BusinessLogic\Http\DTO
 */
class Amount extends BaseDto
{
    const DEFAULT_CURRENCY = 'EUR';
    const DEFAULT_MINOR_UNITS = 2;

    /**
     * @var float
     */
    protected $value;
    /**
     * @var string
     */
    protected $currency;

    /**
     * Mapping between all the currencies and their respective amount minor units.
     *
     * This static property is automatically generated by running the
     * php mollie generate-currency-amount-map command.
     *
     * @var array
     */
    /* <auto-generated> */
    private static $map = array(
        'BHD' => 3,
        'CLF' => 4,
        'IQD' => 3,
        'JOD' => 3,
        'KWD' => 3,
        'LYD' => 3,
        'OMR' => 3,
        'TND' => 3,
        'UYW' => 4,
    );
    /* </auto-generated> */

    /**
     * @inheritDoc
     */
    public static function fromArray(array $raw)
    {
        $result = new static();

        $result->setAmountValue(static::getValue($raw, 'value', 0.00));
        $result->setCurrency(static::getValue($raw, 'currency', static::DEFAULT_CURRENCY));

        return $result;
    }

    /**
     * Instantiates an amount DTO from the smallest unit in a given currency.
     *
     * @param int $smallestUnitValue
     * @param string $currency
     *
     * @return static
     */
    public static function fromSmallestUnit($smallestUnitValue, $currency)
    {
        $instance = new static();

        $instance->initFromSmallestUnit($currency, $smallestUnitValue);

        return $instance;
    }

    /**
     * @inheritDoc
     */
    public function toArray()
    {
        return array(
            'value' => $this->getAmountValue(),
            'currency' => $this->getCurrency(),
        );
    }

    /**
     * @return string
     */
    public function getAmountValue()
    {
        return number_format(
            $this->value,
            $this->getMinorUnits(),
            '.',
            ''
        );
    }

    /**
     * Returns integer value of the amount in smallest currency units.
     *
     * @return int
     */
    public function getAmountValueInSmallestUnit()
    {
        return (int)((float)$this->value * pow(10, $this->getMinorUnits()));
    }

    /**
     * @param float $value
     */
    public function setAmountValue($value)
    {
        $this->value = $value;
    }

    /**
     * @return string
     */
    public function getCurrency()
    {
        return $this->currency;
    }

    /**
     * @param string $currency
     */
    public function setCurrency($currency)
    {
        $this->currency = strtoupper($currency);
    }

    /**
     * Returns the number of minor units for the provided currency.
     *
     * @return int
     */
    protected function getMinorUnits()
    {
        if (!array_key_exists($this->getCurrency(), static::$map)) {
            return static::DEFAULT_MINOR_UNITS;
        }

        return static::$map[$this->getCurrency()];
    }

    /**
     * Initializes amount instance from smallest unit value.
     *
     * @param string $currency
     * @param int $smallestUnitValue
     */
    private function initFromSmallestUnit($currency, $smallestUnitValue)
    {
        // Currency must be set before the value in order to read from the map.
        // Do not change the order of the setters.
        $this->setCurrency($currency);
        $this->setAmountValue((float)$smallestUnitValue / (float)pow(10, $this->getMinorUnits()));
    }
}
