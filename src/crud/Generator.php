<?php
/**
 * Gii Custom.
 *
 * @author Aleksei Korotin <herr.offizier@gmail.com>
 */

namespace herroffizier\gii\crud;

use Yii;
use yii\gii\CodeFile;
use yii\web\Controller;

/**
 * Generates simplified CRUD.
 *
 * Based on Gii CRUD Generator.
 */
class Generator extends \yii\gii\generators\crud\Generator
{
    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return 'Simplified CRUD Generator';
    }

    /**
     * {@inheritdoc}
     */
    public function getDescription()
    {
        return 'Acts like default CRUD but does not generate view and search pages.';
    }

    /**
     * {@inheritdoc}
     */
    public function generate()
    {
        $controllerFile = Yii::getAlias('@'.str_replace('\\', '/', ltrim($this->controllerClass, '\\')).'.php');

        $files = [
            new CodeFile($controllerFile, $this->render('controller.php')),
        ];

        if (!empty($this->searchModelClass)) {
            $searchModel = Yii::getAlias('@'.str_replace('\\', '/', ltrim($this->searchModelClass, '\\').'.php'));
            $files[] = new CodeFile($searchModel, $this->render('search.php'));
        }

        $viewPath = $this->getViewPath();
        $templatePath = $this->getTemplatePath().'/views';
        foreach (scandir($templatePath) as $file) {
            if (is_file($templatePath.'/'.$file) && pathinfo($file, PATHINFO_EXTENSION) === 'php') {
                $files[] = new CodeFile("$viewPath/$file", $this->render("views/$file"));
            }
        }

        return $files;
    }
}
