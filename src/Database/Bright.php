<?php

declare(strict_types=1);

namespace Diviky\Bright\Database;

use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * @SuppressWarnings(PHPMD)
 */
class Bright
{
    /**
     * Start quote.
     *
     * @var string
     */
    protected $startQuote = '`';

    /**
     * End quote.
     *
     * @var string
     */
    protected $endQuote = '`';

    /**
     * Database keyword used to assign aliases to identifiers.
     *
     * @var string
     */
    protected $alias = 'AS ';

    /**
     * The set of valid SQL operations usable in a WHERE statement.
     *
     * @var array
     */
    protected $sqlOps = ['like', 'ilike', 'or', 'not', 'in', 'between', 'regexp', 'similar to'];

    /**
     * Builds and generates an SQL statement from an array.  Handles final clean-up before conversion.
     *
     * @param array  $query An array defining an SQL query
     * @param object $model The model object which initiated the query
     * @param mixed  $table
     * @param mixed  $type
     *
     * @return null|string An executable SQL statement
     */
    public function buildStatement(&$query, $table = '', $type = 'select')
    {
        $table = ($query['table']) ? $query['table'] : $table;

        $query = \array_merge(['offset' => null, 'joins' => []], $query);
        if (!empty($query['joins'])) {
            $count = \count($query['joins']);
            for ($i = 0; $i < $count; ++$i) {
                if (\is_array($query['joins'][$i])) {
                    $query['joins'][$i] = $this->buildJoinStatement($query['joins'][$i]);
                }
            }
        }

        return $this->renderStatement($type, [
            'conditions' => $this->conditions($query['conditions'], true, true),
            'fields' => (\count($query['fields']) > 0) ? \implode(', ', $query['fields']) : ' * ',
            'values' => (\count($query['values']) > 0) ? \implode(', ', $query['values']) : '',
            'table' => $table,
            'alias' => ($query['alias']) ? $this->alias . $this->name($query['alias']) : '',
            'order' => $this->order($query['order']),
            'limit' => $this->limit($query['limit'], $query['offset'], $query['page']),
            'joins' => \implode(' ', $query['joins']),
            'group' => $this->group($query['group']),
        ]);
    }

    /**
     * Renders a final SQL JOIN statement.
     *
     * @param array $data
     *
     * @return string
     */
    public function renderJoinStatement($data)
    {
        \extract($data);

        return \trim('' . $data['type'] . ' JOIN ' . $data['table'] . ' ' . $data['alias'] . ' ON (' . $data['conditions'] . ')');
    }

    /**
     * Alias function for buildStatement.
     *
     * @param string $table
     * @param array  $params
     * @param mixed  $conditions
     */
    public function buildConditions($conditions = []): string
    {
        return $this->conditions($conditions);
    }

    /**
     * Builds and generates a JOIN statement from an array.  Handles final clean-up before conversion.
     *
     * @param array $join An array defining a JOIN statement in a query
     *
     * @return string An SQL JOIN statement to be used in a query
     */
    public function buildJoinStatement($join)
    {
        $data = \array_merge([
            'type' => null,
            'alias' => null,
            'table' => 'join_table',
            'conditions' => [],
        ], $join);

        if (!empty($data['alias'])) {
            $data['alias'] = $this->alias . $this->name($data['alias']);
        }
        if (!empty($data['conditions'])) {
            $data['conditions'] = \trim($this->conditions($data['conditions'], true, false));
        }

        return $this->renderJoinStatement($data);
    }

    /**
     * Renders a final SQL statement by putting together the component parts in the correct order.
     *
     * @param string $type
     * @param array  $data
     */
    public function renderStatement($type, $data): ?string
    {
        $aliases = null;

        switch (\strtolower($type)) {
            case 'select':
                return 'SELECT ' . $data['fields'] . ' FROM ' . $data['table'] . ' ' . $data['alias'] . ' ' . $data['joins'] . ' ' . $data['conditions'] . ' ' . $data['group'] . ' ' . $data['order'] . ' ' . $data['limit'];

                break;
            case 'create':
            case 'insert':
                $values = $data['values'];
                $values = \rtrim($values, ')');
                $values = \ltrim($values, '(');

                return 'INSERT INTO ' . $data['table'] . ' (' . $data['fields'] . ") VALUES ({$values})";

                break;
            case 'update':
                if (!empty($data['alias'])) {
                    $aliases = $this->alias . $data['alias'] . ' ' . $data['joins'] . ' ';
                }

                return 'UPDATE ' . $data['table'] . " {$aliases}SET " . $data['fields'] . ' ' . $data['conditions'] . '';

                break;
            case 'delete':
                if (!empty($data['alias'])) {
                    $aliases = "{$this->alias}" . $data['alias'] . ' ' . $data['joins'] . ' ';
                }

                return 'DELETE ' . $data['alias'] . ' FROM ' . $data['table'] . " {$aliases} " . $data['conditions'] . ' ' . $data['limit'] . '';

                break;
        }

        return null;
    }

    /**
     * Creates a WHERE clause by parsing given conditions data.  If an array or string
     * conditions are provided those conditions will be parsed and quoted.  If a boolean
     * is given it will be integer cast as condition.  Null will return 1 = 1.
     *
     * @param mixed $conditions  Array or string of conditions, or any value
     * @param bool  $quoteValues If true, values should be quoted
     * @param bool  $where       If true, "WHERE " will be prepended to the return value
     * @param Model $model       A reference to the Model instance making the query
     *
     * @return string SQL fragment
     */
    public function conditions($conditions, $quoteValues = true, $where = false)
    {
        $clause = $out = '';

        if ($where) {
            $clause = ' WHERE ';
        }

        if (\is_array($conditions) && !empty($conditions)) {
            $out = $this->conditionKeysToString($conditions, $quoteValues);

            if (empty($out)) {
                return $clause . ' 1 = 1';
            }

            return $clause . \implode(' AND ', $out);
        }
        if (false === $conditions || true === $conditions) {
            return $clause . (int) $conditions . ' = 1';
        }

        if (empty($conditions) || '' == \trim($conditions)) {
            return $clause . '1 = 1';
        }
        $clauses = '/^WHERE\\x20|^GROUP\\x20BY\\x20|^HAVING\\x20|^ORDER\\x20BY\\x20/i';

        if (\preg_match($clauses, $conditions)) {
            $clause = '';
        }
        if ('' == \trim($conditions)) {
            $conditions = ' 1 = 1';
        } else {
            $conditions = $this->quoteFields($conditions);
        }

        return $clause . $conditions;
    }

    /**
     * Creates a WHERE clause by parsing given conditions array.  Used by DboSource::conditions().
     *
     * @param array $conditions  Array or string of conditions
     * @param bool  $quoteValues If true, values should be quoted
     * @param Model $model       A reference to the Model instance making the query
     *
     * @return string[] SQL fragment
     *
     * @psalm-return list<string>
     */
    public function conditionKeysToString($conditions, $quoteValues = true): array
    {
        $i = 0;
        $out = [];
        $data = $columnType = null;
        $bool = ['and', 'or', 'not', 'and not', 'or not', 'xor', '||', '&&'];

        foreach ($conditions as $key => $value) {
            $join = ' AND ';
            $not = '';
            $valueInsert = false;
            if (\is_array($value)) {
                $valueInsert = (
                    !empty($value)
                    && (\substr_count((string) $key, '?') == \count($value) || \substr_count((string) $key, ':') == \count($value))
                );
            }

            if (\is_numeric($key) && empty($value)) {
                continue;
            }
            if (\is_numeric($key) && \is_string($value)) {
                $out[] = $not . $this->quoteFields($value);
            } elseif ((\is_numeric($key) && \is_array($value)) || \in_array(\strtolower(\trim((string) $key)), $bool)) {
                if (\in_array(\strtolower(\trim((string) $key)), $bool)) {
                    $join = ' ' . \strtoupper($key) . ' ';
                } else {
                    $key = $join;
                }
                $value = $this->conditionKeysToString($value, $quoteValues);

                if (false !== \strpos($join, 'NOT')) {
                    if ('NOT' == \strtoupper(\trim($key))) {
                        $key = 'AND ' . \trim($key);
                    }
                    $not = 'NOT ';
                }

                if (empty($value[1])) {
                    if ($not) {
                        $out[] = $not . '(' . $value[0] . ')';
                    } else {
                        $out[] = $value[0];
                    }
                } else {
                    $out[] = '(' . $not . '(' . \implode(') ' . \strtoupper($key) . ' (', $value) . '))';
                }
            } else {
                if (\is_array($value) && !empty($value) && !$valueInsert) {
                    if (\array_keys($value) === \array_values(\array_keys($value))) {
                        $data = $this->quoteFields($key) . ' IN (';
                        if ($quoteValues || 0 !== \strpos($value[0], '-!')) {
                            $data .= \implode(', ', $this->value($value, $columnType));
                        }
                        $data .= ')';
                    } else {
                        $ret = $this->conditionKeysToString($value, $quoteValues);
                        if (\count($ret) > 1) {
                            $data = '(' . \implode(') AND (', $ret) . ')';
                        } elseif (isset($ret[0])) {
                            $data = $ret[0];
                        }
                    }
                } elseif (\is_numeric($key) && !empty($value)) {
                    $data = $this->quoteFields($value);
                } else {
                    $data = $this->parseKey(\trim($key), $value);
                }

                if (null != $data) {
                    if (\preg_match('/^\(\(\((.+)\)\)\)$/', $data)) {
                        $data = \substr($data, 1, \strlen($data) - 2);
                    }
                    $out[] = $data;
                    $data = null;
                }
            }
            ++$i;
        }

        return $out;
    }

    /**
     * Returns a limit statement in the correct format for the particular database.
     *
     * @param int        $limit  Limit of results returned
     * @param int        $offset Offset from which to start results
     * @param null|mixed $page
     *
     * @return null|string SQL limit/offset statement
     */
    public function limit($limit, $offset = null, $page = null): ?string
    {
        if ($limit) {
            $rt = '';
            $limit = strval($limit);
            if (!\stripos($limit, 'limit') || 0 === \strpos(\strtolower($limit), 'limit')) {
                $rt = ' LIMIT';
            }

            if ((int) $offset) {
                $rt .= ' ' . $offset . ',';
            }

            if ((int) $page && !$offset) {
                $rt .= ' ' . $limit * ($page - 1) . ',';
            }

            $rt .= ' ' . $limit;

            return $rt;
        }

        return null;
    }

    /**
     * Returns an ORDER BY clause as a string.
     *
     * @param string $key       Field reference, as a key (i.e. Post.title)
     * @param string $direction Direction (ASC or DESC)
     * @param mixed  $keys
     *
     * @return string ORDER BY clause
     */
    public function order($keys, $direction = 'ASC')
    {
        if (\is_string($keys) && \strpos($keys, ',') && !\preg_match('/\(.+\,.+\)/', $keys)) {
            $keys = \array_map('trim', \explode(',', $keys));
        }

        if (\is_array($keys)) {
            $keys = \array_filter($keys);
        }

        if (empty($keys) || (\is_array($keys) && isset($keys[0]) && empty($keys[0]))) {
            return '';
        }

        if (\is_array($keys)) {
            $keys = ($this->countDim($keys) > 1) ? \array_map([&$this, 'order'], $keys) : $keys;
            $order = [];
            foreach ($keys as $key => $value) {
                if (\is_numeric($key)) {
                    $key = $value = \ltrim(Str::replace('ORDER BY ', '', $this->order($value)));
                    $value = (!\preg_match('/\\x20ASC|\\x20DESC/i', $key) ? ' ' . $direction : '');
                } else {
                    $value = ' ' . $value;
                }

                if (!\preg_match('/^.+\\(.*\\)/', $key) && !\strpos($key, ',')) {
                    if (\preg_match('/\\x20ASC|\\x20DESC/i', $key, $dir)) {
                        $dir = $dir[0];
                        $key = \preg_replace('/\\x20ASC|\\x20DESC/i', '', $key);
                    } else {
                        $dir = '';
                    }
                    $key = \trim($key);
                    if (!\preg_match('/\s/', $key)) {
                        $key = $this->name($key);
                    }
                    $key .= ' ' . \trim($dir);
                }
                $order[] = $this->order($key . $value);
            }

            return ' ORDER BY ' . \trim(Str::replace('ORDER BY', '', \implode(',', $order)));
        }
        $keys = \preg_replace('/ORDER\\x20BY/i', '', $keys);

        if (\strpos($keys, '.')) {
            \preg_match_all('/([a-zA-Z0-9_]{1,})\\.([a-zA-Z0-9_]{1,})/', $keys, $result, PREG_PATTERN_ORDER);
            $pregCount = \count($result[0]);

            for ($i = 0; $i < $pregCount; ++$i) {
                if (!\is_numeric($result[0][$i])) {
                    $keys = \preg_replace('/' . $result[0][$i] . '/', $this->name($result[0][$i]), $keys);
                }
            }
            $result = ' ORDER BY ' . $keys;

            return $result . (!\preg_match('/\\x20ASC|\\x20DESC/i', $keys) ? ' ' . $direction : '');
        }
        if (\preg_match('/(\\x20ASC|\\x20DESC)/i', $keys, $match)) {
            $direction = $match[1];

            return ' ORDER BY ' . \preg_replace('/' . $match[1] . '/', '', $keys) . $direction;
        }

        return ' ORDER BY ' . $keys . ' ' . $direction;
    }

    /**
     * Create a GROUP BY SQL clause.
     *
     * @param array|string $group Group By Condition
     *
     * @return null|string string condition or null
     */
    public function group($group)
    {
        if ($group) {
            if (\is_array($group)) {
                $group = \implode(', ', $group);
            }

            return ' GROUP BY ' . $this->quoteFields($group);
        }

        return null;
    }

    /**
     * Prepares a value, or an array of values for database queries by quoting and escaping them.
     *
     * @param mixed  $data   A value or an array of values to prepare
     * @param string $column The column into which this data will be inserted
     * @param bool   $read   Value to be used in READ or WRITE context
     *
     * @return mixed Prepared value or array of values
     */
    public function value($data, $column = null, $read = true)
    {
        if (\is_array($data) && !empty($data)) {
            return \array_map(
                [&$this, 'value'],
                $data,
                \array_fill(0, \count($data), $column),
                \array_fill(0, \count($data), $read)
            );
        }

        if (\is_string($data)) {
            return "'" . $data . "'";
        }

        if ($data instanceof Carbon) {
            return "'" . $data . "'";
        }

        if (\is_null($data)) {
            return "''";
        }

        return $data;
    }

    /**
     * Returns a quoted name of $data for use in an SQL statement.
     * Strips fields out of SQL functions before quoting.
     *
     * @param mixed $data
     *
     * @return mixed SQL field
     */
    public function name($data)
    {
        if ('*' == $data) {
            return '*';
        }
        if (\is_object($data) && isset($data->type)) {
            return $data->value;
        }
        $array = \is_array($data);
        $data = (array) $data;
        $count = \count($data);

        for ($i = 0; $i < $count; ++$i) {
            if ('*' == $data[$i]) {
                continue;
            }
            if (false !== \strpos($data[$i], '(') && \preg_match_all('/([^(]*)\((.*)\)(.*)/', $data[$i], $fields)) {
                $fe = [];
                foreach ($fields as $field) {
                    $fe[] = $field[0];
                }

                $fields = $fe;

                if (!empty($fields[1])) {
                    if (!empty($fields[2])) {
                        $data[$i] = $fields[1] . '(' . $this->name($fields[2]) . ')' . $fields[3];
                    } else {
                        $data[$i] = $fields[1] . '()' . $fields[3];
                    }
                }
            }
            $data[$i] = Str::replace('.', $this->endQuote . '.' . $this->startQuote, $data[$i]);
            $data[$i] = $this->startQuote . $data[$i] . $this->endQuote;
            $data[$i] = Str::replace($this->startQuote . $this->startQuote, $this->startQuote, $data[$i]);
            $data[$i] = Str::replace($this->startQuote . '(', '(', $data[$i]);
            $data[$i] = Str::replace(')' . $this->startQuote, ')', $data[$i]);
            $alias = !empty($this->alias) ? $this->alias : 'AS ';

            if (\preg_match('/\s+' . $alias . '\s*/', $data[$i])) {
                if (\preg_match('/\w+\s+' . $alias . '\s*/', $data[$i])) {
                    $quoted = $this->endQuote . ' ' . $alias . $this->startQuote;
                    $data[$i] = Str::replace(' ' . $alias, $quoted, $data[$i]);
                } else {
                    $quoted = $alias . $this->startQuote;
                    $data[$i] = Str::replace($alias, $quoted, $data[$i]) . $this->endQuote;
                }
            }

            if (!empty($this->endQuote) && $this->endQuote == $this->startQuote) {
                if (1 == \substr_count($data[$i], $this->endQuote) % 2) {
                    if (\substr($data[$i], -2) == $this->endQuote . $this->endQuote) {
                        $data[$i] = \substr($data[$i], 0, -1);
                    } else {
                        $data[$i] = \trim($data[$i], $this->endQuote);
                    }
                }
            }
            if (\strpos($data[$i], '*')) {
                $data[$i] = Str::replace($this->endQuote . '*' . $this->endQuote, '*', $data[$i]);
            }
            $data[$i] = Str::replace($this->endQuote . $this->endQuote, $this->endQuote, $data[$i]);
        }

        return (!$array) ? $data[0] : $data;
    }

    /**
     * Counts the dimensions of an array. If $all is set to false (which is the default) it will
     * only consider the dimension of the first element in the array.
     *
     * @param array $array Array to count dimensions on
     * @param bool  $all   Set to true to count the dimension considering all elements in array
     * @param int   $count Start the dimension count at this number
     *
     * @return int The number of dimensions in $array
     *
     * @static
     */
    public function countDim($array = null, $all = false, $count = 0)
    {
        if ($all) {
            $depth = [$count];
            if (\is_array($array) && false !== \reset($array)) {
                foreach ($array as $value) {
                    $depth[] = $this->countDim($value, true, $count + 1);
                }
            }
            $return = \max($depth);
        } else {
            if (\is_array(\reset($array))) {
                $return = $this->countDim(\reset($array)) + 1;
            } else {
                $return = 1;
            }
        }

        return $return;
    }

    /**
     * Extracts a Model.field identifier and an SQL condition operator from a string, formats
     * and inserts values, and composes them into an SQL snippet.
     *
     * @param Model  $model Model object initiating the query
     * @param string $key   An SQL key snippet containing a field and optional SQL operator
     * @param mixed  $value The value(s) to be inserted in the string
     *
     * @return string
     */
    protected function parseKey($key, $value)
    {
        $operatorMatch = '/^((' . \implode(')|(', $this->sqlOps);
        $operatorMatch .= '\\x20)|<[>=]?(?![^>]+>)\\x20?|[>=!]{1,3}(?!<)\\x20?)/is';
        $bound = (false !== \strpos($key, '?') || (\is_array($value) && false !== \strpos($key, ':')));

        if (!\strpos($key, ' ')) {
            $operator = '=';
        } else {
            list($key, $operator) = \explode(' ', \trim($key), 2);

            if (!\preg_match($operatorMatch, \trim($operator)) && false !== \strpos($operator, ' ')) {
                $key = $key . ' ' . $operator;
                $split = \strrpos($key, ' ');
                if (false !== $split) {
                    $operator = \substr($key, $split);
                    $key = \substr($key, 0, $split);
                }
            }
        }

        $type = null;

        $null = (null === $value || (\is_array($value) && empty($value)));

        if ('not' === \strtolower($operator)) {
            $values = [];
            $values[$operator] = [];
            $values[$operator][$key] = $value;

            $data = $this->conditionKeysToString($values, true);

            return $data[0];
        }

        $value = $this->value($value, $type);

        if ('?' !== $key) {
            $isKey = (false !== \strpos($key, '(') || false !== \strpos($key, ')'));
            $key = $isKey ? $this->quoteFields($key) : $this->name($key);
        }

        if ($bound) {
            return $this->replace($key . ' ' . \trim($operator), $value);
        }

        if (!\preg_match($operatorMatch, \trim($operator))) {
            $operator .= ' =';
        }
        $operator = \trim($operator);

        if (\is_array($value)) {
            $value = \implode(', ', $value);

            switch ($operator) {
                case '=':
                    $operator = 'IN';

                    break;
                case '!=':
                case '<>':
                    $operator = 'NOT IN';

                    break;
            }
            $value = "({$value})";
        } elseif ($null || 'NULL' === $value) {
            switch ($operator) {
                case '=':
                    $operator = 'IS';

                    break;
                case '!=':
                case '<>':
                    $operator = 'IS NOT';

                    break;
            }
            $value = 'NULL';
        }

        return "{$key} {$operator} {$value}";
    }

    /**
     * Replace the string.
     *
     * @param mixed $value
     *
     * @return string
     */
    protected function replace(string $str, $value = [])
    {
        if (!\is_array($value)) {
            $value = [$value];
        }

        if (false !== \strpos($str, '?') && \is_numeric(\key($value))) {
            $offset = 0;
            while (false !== ($pos = \strpos($str, '?', $offset))) {
                $val = \array_shift($value);
                $offset = $pos + \strlen($val);
                $str = \substr_replace($str, $val, $pos, 1);
            }

            return $str;
        }

        foreach ($value as $key => $val) {
            $str = Str::replace(':' . $key, $val, $str);
        }

        return $str;
    }

    /**
     * Quotes Model.fields.
     *
     * @param string $conditions
     *
     * @return string or false if no match
     */
    protected function quoteFields($conditions)
    {
        $start = $end = '';
        $original = $conditions;

        if (!empty($this->startQuote)) {
            $start = \preg_quote($this->startQuote);
        }
        if (!empty($this->endQuote)) {
            $end = \preg_quote($this->endQuote);
        }
        $conditions = Str::replace([$start, $end], '', $conditions);
        \preg_match_all('/(?:[\'\"][^\'\"\\\]*(?:\\\.[^\'\"\\\]*)*[\'\"])|([a-z0-9_' . $start . $end . ']*\\.[a-z0-9_' . $start . $end . ']*)/i', $conditions, $replace, PREG_PATTERN_ORDER);

        if (isset($replace['1']['0'])) {
            $pregCount = \count($replace['1']);

            for ($i = 0; $i < $pregCount; ++$i) {
                if (!empty($replace['1'][$i]) && !\is_numeric($replace['1'][$i])) {
                    $conditions = \preg_replace('/\b' . \preg_quote($replace['1'][$i]) . '\b/', $this->name($replace['1'][$i]), $conditions);
                }
            }

            return $conditions;
        }

        return $original;
    }
}
