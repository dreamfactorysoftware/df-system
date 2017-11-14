<?php
namespace DreamFactory\Core\GraphQL\Type;

use DreamFactory\Core\Enums\DbSimpleTypes;
use DreamFactory\Core\Models\BaseModel;
use Folklore\GraphQL\Support\Type as GraphQLType;
use GraphQL\Type\Definition\Type;

class BaseType extends GraphQLType
{
    /*
    * Uncomment following line to make the type input object.
    * http://graphql.org/learn/schema/#input-types
    */
    // protected $inputObject = true;

    /**
     * @return array|null
     */
    public function fields()
    {
        $out = [];
        if ($modelClass = array_get($this->attributes, 'model')) {
            /** @var BaseModel $model */
            $model = new $modelClass;
            $schema = $model->getTableSchema();
            if ($schema) {
                foreach ($schema->getColumns(true) as $name => $column) {
                    switch ($column->type) {
                        case DbSimpleTypes::TYPE_BOOLEAN:
                            $type = Type::boolean();
                            break;
                        case DbSimpleTypes::TYPE_INTEGER:
                            $type = Type::boolean();
                            break;
                        case DbSimpleTypes::TYPE_FLOAT:
                            $type = Type::float();
                            break;
                        default:
                            $type = Type::string();
                            break;
                    }
                    if (!$column->allowNull) {
                        $type = Type::nonNull($type);
                    }
                    $out[$name] = ['type' => $type, 'description' => $column->getLabel(true)];
                }
            }
        }

        return $out;
    }
}