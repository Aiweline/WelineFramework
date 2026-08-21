<?php
/**
 * @package Weline_Framework Font (derived from php-font-lib)
 * @link    https://github.com/dompdf/php-font-lib (upstream)
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */
namespace Weline\Theme\Font;

use Weline\Theme\Font\TrueType\File;

/**
 * Font header container.
 *
 * @package Weline_Framework Font (derived from php-font-lib)
 */
abstract class Header extends BinaryStream {
  /**
   * @var File
   */
  protected $font;
  protected $def = array();

  public $data;

  public function __construct(File $font) {
    $this->font = $font;
  }

  public function encode() {
    return $this->font->pack($this->def, $this->data);
  }

  public function parse() {
    $this->data = $this->font->unpack($this->def);
  }
}