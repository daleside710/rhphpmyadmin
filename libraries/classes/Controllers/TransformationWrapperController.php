<?php
declare(strict_types=1);

namespace PhpMyAdmin\Controllers;

use PhpMyAdmin\Core;
use PhpMyAdmin\DatabaseInterface;
use PhpMyAdmin\DbTableExists;
use PhpMyAdmin\Relation;
use PhpMyAdmin\Response;
use PhpMyAdmin\Template;
use PhpMyAdmin\Transformations;
use PhpMyAdmin\Util;
use function define;
use function htmlspecialchars;
use function imagecopyresampled;
use function imagecreatefromstring;
use function imagecreatetruecolor;
use function imagedestroy;
use function imagejpeg;
use function imagepng;
use function imagesx;
use function imagesy;
use function in_array;
use function intval;
use function str_replace;
use function stripos;
use function substr;

/**
 * Wrapper script for rendering transformations
 */
class TransformationWrapperController extends AbstractController
{
    /** @var Transformations */
    private $transformations;

    /** @var Relation */
    private $relation;

    /**
     * @param Response          $response        Response object
     * @param DatabaseInterface $dbi             DatabaseInterface object
     * @param Template          $template        Template object
     * @param Transformations   $transformations Transformations object
     * @param Relation          $relation        Relation object
     */
    public function __construct(
        $response,
        $dbi,
        Template $template,
        Transformations $transformations,
        Relation $relation
    ) {
        parent::__construct($response, $dbi, $template);
        $this->transformations = $transformations;
        $this->relation = $relation;
    }

    public function index(): void
    {
        global $cn, $db, $table, $transform_key, $request_params, $size_params, $where_clause, $row;
        global $default_ct, $mime_map, $mime_options, $ct, $mime_type, $srcImage, $srcWidth, $srcHeight;
        global $ratioWidth, $ratioHeight, $destWidth, $destHeight, $destImage;

        define('IS_TRANSFORMATION_WRAPPER', true);

        $cfgRelation = $this->relation->getRelationsParam();

        DbTableExists::check();

        /**
         * Sets globals from $_REQUEST
         */
        $request_params = [
            'cn',
            'ct',
            'sql_query',
            'transform_key',
            'where_clause',
        ];
        $size_params = [
            'newHeight',
            'newWidth',
        ];
        foreach ($request_params as $one_request_param) {
            if (isset($_REQUEST[$one_request_param])) {
                if (in_array($one_request_param, $size_params)) {
                    $GLOBALS[$one_request_param] = intval($_REQUEST[$one_request_param]);
                    if ($GLOBALS[$one_request_param] > 2000) {
                        $GLOBALS[$one_request_param] = 2000;
                    }
                } else {
                    $GLOBALS[$one_request_param] = $_REQUEST[$one_request_param];
                }
            }
        }

        /**
         * Get the list of the fields of the current table
         */
        $this->dbi->selectDb($db);
        if (isset($where_clause)) {
            $result = $this->dbi->query(
                'SELECT * FROM ' . Util::backquote($table)
                . ' WHERE ' . $where_clause . ';',
                DatabaseInterface::CONNECT_USER,
                DatabaseInterface::QUERY_STORE
            );
            $row = $this->dbi->fetchAssoc($result);
        } else {
            $result = $this->dbi->query(
                'SELECT * FROM ' . Util::backquote($table) . ' LIMIT 1;',
                DatabaseInterface::CONNECT_USER,
                DatabaseInterface::QUERY_STORE
            );
            $row = $this->dbi->fetchAssoc($result);
        }

        // No row returned
        if (! $row) {
            return;
        }

        $default_ct = 'application/octet-stream';

        if ($cfgRelation['commwork'] && $cfgRelation['mimework']) {
            $mime_map = $this->transformations->getMime($db, $table);
            $mime_options = $this->transformations->getOptions(
                $mime_map[$transform_key]['transformation_options'] ?? ''
            );

            foreach ($mime_options as $key => $option) {
                if (substr($option, 0, 10) == '; charset=') {
                    $mime_options['charset'] = $option;
                }
            }
        }

        $this->response->getHeader()->sendHttpHeaders();

        // [MIME]
        if (isset($ct) && ! empty($ct)) {
            $mime_type = $ct;
        } else {
            $mime_type = (! empty($mime_map[$transform_key]['mimetype'])
                    ? str_replace('_', '/', $mime_map[$transform_key]['mimetype'])
                    : $default_ct)
                . ($mime_options['charset'] ?? '');
        }

        Core::downloadHeader($cn, $mime_type);

        if (! isset($_REQUEST['resize'])) {
            if (stripos($mime_type, 'html') === false) {
                echo $row[$transform_key];
            } else {
                echo htmlspecialchars($row[$transform_key]);
            }
        } else {
            // if image_*__inline.inc.php finds that we can resize,
            // it sets the resize parameter to jpeg or png

            $srcImage = imagecreatefromstring($row[$transform_key]);
            $srcWidth = imagesx($srcImage);
            $srcHeight = imagesy($srcImage);

            // Check to see if the width > height or if width < height
            // if so adjust accordingly to make sure the image
            // stays smaller than the new width and new height

            $ratioWidth = $srcWidth / $_REQUEST['newWidth'];
            $ratioHeight = $srcHeight / $_REQUEST['newHeight'];

            if ($ratioWidth < $ratioHeight) {
                $destWidth = $srcWidth / $ratioHeight;
                $destHeight = $_REQUEST['newHeight'];
            } else {
                $destWidth = $_REQUEST['newWidth'];
                $destHeight = $srcHeight / $ratioWidth;
            }

            if ($_REQUEST['resize']) {
                $destImage = imagecreatetruecolor($destWidth, $destHeight);

                // ImageCopyResized($destImage, $srcImage, 0, 0, 0, 0,
                // $destWidth, $destHeight, $srcWidth, $srcHeight);
                // better quality but slower:
                imagecopyresampled(
                    $destImage,
                    $srcImage,
                    0,
                    0,
                    0,
                    0,
                    $destWidth,
                    $destHeight,
                    $srcWidth,
                    $srcHeight
                );
                if ($_REQUEST['resize'] == 'jpeg') {
                    imagejpeg($destImage, null, 75);
                }
                if ($_REQUEST['resize'] == 'png') {
                    imagepng($destImage);
                }
                imagedestroy($destImage);
            }
            imagedestroy($srcImage);
        }
    }
}
