<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Matcher;

use TanoWAF\WAFCore\Exception\ConfigurationError;

/**
 * Implements functionality common to Matcher factories:
 * - optional postfixes to the matcher type
 * - matcher with options
 */
abstract class OptionAwareMatcherFactory extends SuffixedMatcherFactory
{
    // NB: should not conflict with $this->matcherTypeSuffixRegexp
    protected string $optionSeparatorChar = '/';

    protected function getMatcherType(string $type): string
    {
        $type = parent::getMatcherType($type);
        return str_contains($type, $this->optionSeparatorChar) ? strstr($type, $this->optionSeparatorChar, true) : $type;
    }

    /**
     * Splits the type string based on $this->optionSeparatorChar, looking for boolean options (ie. options which can
     * be either present or absent).
     * @param bool[] $options key: name of the option, value: bool. The default value to be returned. The value will be
     *                        flipped in the returned array when the option is present in the type string
     * @param int $optionsOffset to be used when there are a number of mandatory options before the optional ones,
     *                           eg. 'matcher_type/mandatory_opt_1/option_x/option_y' => set 2
     * @return bool[] an array with the same keys as $options
     * @throws ConfigurationError
     */
    protected function parseMatcherBooleanOptions(string $type, array $options, int $optionsOffset = 1): array
    {
        // remove a suffix such as `:1`, which can be used to obviate to key uniqueness issues
        $typeWithOptions = parent::getMatcherType($type);
        $data = explode($this->optionSeparatorChar, $typeWithOptions);
        $out = $options;
        for ($i = $optionsOffset; $i < count($data); $i++) {
            $optionName = $data[$i];
            if (!array_key_exists($optionName, $options)) {
                throw new ConfigurationError("Matcher modifier '{$this->optionSeparatorChar}{$optionName}' is not supported");
            }
            $out[$optionName] = !$options[$optionName];
        }
        return $out;
    }

    protected function getMatcherOptionByPosition(string $type, int $position, string $defaultValue = ''): string
    {
        $typeWithOptions = parent::getMatcherType($type);
        $data = explode($this->optionSeparatorChar, $typeWithOptions);
        return $data[$position] ?? $defaultValue;
    }

    // In case we'll later want to allow options with values...
    //protected function getMatcherOptionByName(string $name, string $defaultValue = '')
    //{
    //
    //}
}
