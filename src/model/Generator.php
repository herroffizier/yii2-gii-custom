<?php
/**
 * Gii Custom.
 *
 * @author Aleksei Korotin <herr.offizier@gmail.com>
 */

namespace herroffizier\gii\model;

use Yii;
use yii\db\ActiveRecord;
use yii\db\TableSchema;
use yii\gii\CodeFile;

/**
 * This generator will generate base and user model classes for specified database table.
 *
 * Based on Gii Model generator.
 */
class Generator extends \yii\gii\generators\model\Generator
{
    public $baseNs = 'app\models\base';

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return 'Base + User Model Generator';
    }

    /**
     * {@inheritdoc}
     */
    public function getDescription()
    {
        return
            'This generator generates base and user model classes for the specified database table. '
            .'Base model should not be touched so it can be easily regenerated when needed. '
            .'User model is a place where you should put your code.';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return array_merge(parent::rules(), [
            [['baseNs'], 'filter', 'filter' => 'trim'],
            [['baseNs'], 'filter', 'filter' => function ($value) {
                return trim($value, '\\');
            }],
            [['baseNs'], 'required'],
            [['baseNs'], 'match', 'pattern' => '/^[\w\\\\]+$/', 'message' => 'Only word characters and backslashes are allowed.'],
            [['baseNs'], 'validateNamespace'],
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return array_merge(parent::attributeLabels(), [
            'ns' => 'Namespace for user model',
            'baseNs' => 'Namespace for base model',
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function hints()
    {
        return array_merge(parent::hints(), [
            'baseNs' => 'This is the namespace of the base ActiveRecord class to be generated, e.g., <code>app\models\base</code>',
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function autoCompleteData()
    {
        $db = $this->getDbConnection();
        if ($db !== null) {
            return [
                'tableName' => function () use ($db) {
                    return $db->getSchema()->getTableNames();
                },
            ];
        } else {
            return [];
        }
    }

    /**
     * {@inheritdoc}
     */
    public function requiredTemplates()
    {
        return ['model.php', 'baseModel.php'];
    }

    /**
     * {@inheritdoc}
     */
    public function stickyAttributes()
    {
        return array_merge(parent::stickyAttributes(), ['baseNs']);
    }

    /**
     * {@inheritdoc}
     */
    public function generate()
    {
        $files = [];
        $relations = $this->generateRelations();
        $db = $this->getDbConnection();
        foreach ($this->getTableNames() as $tableName) {
            // model :
            $modelClassName = $this->generateClassName($tableName);
            $queryClassName = ($this->generateQuery) ? $this->generateQueryClassName($modelClassName) : false;
            $tableSchema = $db->getTableSchema($tableName);
            $params = [
                'tableName' => $tableName,
                'className' => $modelClassName,
                'queryClassName' => $queryClassName,
                'tableSchema' => $tableSchema,
                'labels' => $this->generateLabels($tableSchema),
                'rules' => $this->generateRules($tableSchema),
                'relations' => isset($relations[$tableName]) ? $relations[$tableName] : [],
            ];
            $files[] = new CodeFile(
                Yii::getAlias('@'.str_replace('\\', '/', $this->baseNs)).'/'.$modelClassName.'.php',
                $this->render('baseModel.php', $params)
            );

            $files[] = new CodeFile(
                Yii::getAlias('@'.str_replace('\\', '/', $this->ns)).'/'.$modelClassName.'.php',
                $this->render('model.php', $params)
            );

            // query :
            if ($queryClassName) {
                $params['className'] = $queryClassName;
                $params['modelClassName'] = $modelClassName;
                $files[] = new CodeFile(
                    Yii::getAlias('@'.str_replace('\\', '/', $this->queryNs)).'/'.$queryClassName.'.php',
                    $this->render('query.php', $params)
                );
            }
        }

        return $files;
    }

    /**
     * @return array the generated relation declarations
     */
    protected function generateRelations()
    {
        if ($this->generateRelations === self::RELATIONS_NONE) {
            return [];
        }

        $db = $this->getDbConnection();

        $relations = [];
        foreach ($this->getSchemaNames() as $schemaName) {
            foreach ($db->getSchema()->getTableSchemas($schemaName) as $table) {
                $className = $this->generateClassName($table->fullName);
                foreach ($table->foreignKeys as $refs) {
                    $refTable = $refs[0];
                    $refTableSchema = $db->getTableSchema($refTable);
                    if ($refTableSchema === null) {
                        // Foreign key could point to non-existing table: https://github.com/yiisoft/yii2-gii/issues/34
                        continue;
                    }
                    unset($refs[0]);
                    $fks = array_keys($refs);
                    $refClassName = $this->generateClassName($refTable);

                    // Add relation for this table
                    $link = $this->generateRelationLink(array_flip($refs));
                    $relationName = $this->generateRelationName($relations, $table, $fks[0], false);
                    $relations[$table->fullName][$relationName] = [
                        "return \$this->hasOne(\\{$this->ns}\\$refClassName::className(), $link);",
                        $refClassName,
                        false,
                    ];

                    // Add relation for the referenced table
                    $hasMany = $this->isHasManyRelation($table, $fks);
                    $link = $this->generateRelationLink($refs);
                    $relationName = $this->generateRelationName($relations, $refTableSchema, $className, $hasMany);
                    $relations[$refTableSchema->fullName][$relationName] = [
                        'return $this->'.($hasMany ? 'hasMany' : 'hasOne')."(\\{$this->ns}\\$className::className(), $link);",
                        $className,
                        $hasMany,
                    ];
                }

                if (($junctionFks = $this->checkJunctionTable($table)) === false) {
                    continue;
                }

                $relations = $this->generateManyManyRelations($table, $junctionFks, $relations);
            }
        }

        if ($this->generateRelations === self::RELATIONS_ALL_INVERSE) {
            return $this->addInverseRelations($relations);
        }

        return $relations;
    }

    /**
     * Generates relations using a junction table by adding an extra viaTable().
     *
     * @param \yii\db\TableSchema the table being checked
     * @param array $fks       obtained from the checkJunctionTable() method
     * @param array $relations
     *
     * @return array modified $relations
     */
    private function generateManyManyRelations($table, $fks, $relations)
    {
        $db = $this->getDbConnection();

        foreach ($fks as $pair) {
            list($firstKey, $secondKey) = $pair;
            $table0 = $firstKey[0];
            $table1 = $secondKey[0];
            unset($firstKey[0], $secondKey[0]);
            $className0 = $this->generateClassName($table0);
            $className1 = $this->generateClassName($table1);
            $table0Schema = $db->getTableSchema($table0);
            $table1Schema = $db->getTableSchema($table1);

            $link = $this->generateRelationLink(array_flip($secondKey));
            $viaLink = $this->generateRelationLink($firstKey);
            $relationName = $this->generateRelationName($relations, $table0Schema, key($secondKey), true);
            $relations[$table0Schema->fullName][$relationName] = [
                "return \$this->hasMany(\\{$this->ns}\\$className1::className(), $link)->viaTable('"
                .$this->generateTableName($table->name)."', $viaLink);",
                $className1,
                true,
            ];

            $link = $this->generateRelationLink(array_flip($firstKey));
            $viaLink = $this->generateRelationLink($secondKey);
            $relationName = $this->generateRelationName($relations, $table1Schema, key($firstKey), true);
            $relations[$table1Schema->fullName][$relationName] = [
                "return \$this->hasMany(\\{$this->ns}\\$className0::className(), $link)->viaTable('"
                .$this->generateTableName($table->name)."', $viaLink);",
                $className0,
                true,
            ];
        }

        return $relations;
    }
}
