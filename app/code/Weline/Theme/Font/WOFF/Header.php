<?php
/**
 * @package Weline_Framework Font (derived from php-font-lib)
 * @link    https://github.com/dompdf/php-font-lib (upstream)
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */

namespace Weline\Theme\Font\WOFF;

/**
 * WOFF font file header.
 *
 * @package Weline_Framework Font (derived from php-font-lib)
 */
class Header extends \Weline\Theme\Font\TrueType\Header {
  protected $def = array(
    "format"         => self::uint32,
    "flavor"         => self::uint32,
    "length"         => self::uint32,
    "numTables"      => self::uint16,
    self::uint16,
    "totalSfntSize"  => self::uint32,
    "majorVersion"   => self::uint16,
    "minorVersion"   => self::uint16,
    "metaOffset"     => self::uint32,
    "metaLength"     => self::uint32,
    "metaOrigLength" => self::uint32,
    "privOffset"     => self::uint32,
    "privLength"     => self::uint32,
  );
}