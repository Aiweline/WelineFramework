<?php
/**
 * @package Weline_Framework Font (derived from php-font-lib)
 * @link    https://github.com/dompdf/php-font-lib (upstream)
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */

namespace Weline\Theme\Font\TrueType;

/**
 * TrueType font file header.
 *
 * @package Weline_Framework Font (derived from php-font-lib)
 */
class Header extends \Weline\Theme\Font\Header {
  protected $def = array(
    "format"        => self::uint32,
    "numTables"     => self::uint16,
    "searchRange"   => self::uint16,
    "entrySelector" => self::uint16,
    "rangeShift"    => self::uint16,
  );

  public function parse() {
    parent::parse();

    $format                   = $this->data["format"];
    $this->data["formatText"] = $this->convertUInt32ToStr($format);
  }
}