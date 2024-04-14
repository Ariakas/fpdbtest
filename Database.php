<?php

namespace FpDbTest;

use Exception;
use mysqli;

class Database implements DatabaseInterface
{
    private mysqli $mysqli;

    private function null_value($value): string {
        return "NULL";
    }

    private function convert_qualifier($value, $type = null): string {
        if (in_array($type, [null, "d", "f"]) && is_null($value)) {
            $value =  $this->null_value($value);
        }
        elseif (is_null($type)) {
            if (gettype($value) == "string") {
                $value = $this->mysqli->real_escape_string($value);
                $value = "'$value'";
            }
            elseif (in_array(gettype($value), ["integer", "double", "boolean"])) {
                $value = floatval($value);
            }
            else {
                throw new Exception("Wrong argument type");
            }
        }
        else {
            if ($type == "d") {
                $value = intval($value);
            }
            elseif ($type == "f") {
                $value = floatval($value);
            }
            elseif ($type == "a") {
                if (gettype($value) == "array") {
                    if (array_is_list($value)) {
                        $value = array_map(fn($val) => $this->convert_qualifier($val), $value);
                    }
                    else {
                        $value = array_map(fn($key, $val) => "`$key` = " . $this->convert_qualifier($val), array_keys($value), array_values($value));
                    }
                    $value = implode(", ", $value);
                }
                else {
                    throw new Exception("Qualifier type ?a passed but argument is not an array");
                }
            }
            elseif ($type == "#") {
                if (gettype($value) == "array") {
                    $value = array_map(fn($val) => "`$val`", $value);
                    $value = implode(", ", $value);
                }
                else {
                    $value = "`$value`";
                }
            }
        }
        return "$value";
    }

    public function __construct(mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    public function buildQuery(string $query, array $args = []): string
    {
        preg_match_all("/(\{[^}]*}|\?[dfa#]?)/", $query, $match);
        $specials = $match[0];
        if ($specials) {
            if (count($specials) !== count($args)) {
                throw new Exception("Args count does not match template");
            }
            $conditions = array_filter($specials, fn($val) => $val[0] == "{");
            if ($conditions) {
                foreach ($conditions as $key => $condition) {
                    $preg = preg_quote($condition);
                    if ($args[$key]) {
                        $query = preg_replace("/$preg/", mb_substr($condition, 1, -1), $query, 1);
                        if (!preg_match("/\?[dfa#]?/", $condition)) {
                            unset($args[$key]);
                        }
                    }
                    else {
                        $query = preg_replace("/$preg/", "", $query, 1);
                        unset($args[$key]);
                        unset($specials[$key]);
                    }
                }
                preg_match_all("/\?.{0,1}/", $query, $match);
                $specials = $match[0];
                $args = array_values($args);
            }
            foreach ($specials as $key => $special) {
                if (!in_array($special[1] ?? null, ["d", "f", "a", "#", null])) {
                    throw new Exception("Wrong qualifier type");
                }
                $preg = preg_quote($special);
                $query = preg_replace("/$preg/", $this->convert_qualifier($args[$key], $special[1] ?? null), $query, 1);
            }
        }
        return $query;
    }

    public function skip(): bool
    {
        return false;
    }
}
