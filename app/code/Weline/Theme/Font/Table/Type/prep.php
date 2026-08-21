<?php

/**
 * @package Weline_Framework Font (derived from php-font-lib)
 * @link    https://github.com/dompdf/php-font-lib (upstream)
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */

namespace Weline\Theme\Font\Table\Type;

use Weline\Theme\Font\Table\Table;

/**
 * `prep` font table.
 *
 * @package Weline_Framework Font (derived from php-font-lib)
 */
class prep extends Table
{
  private $rawData;
  protected function _parse() {
    $font = $this->getFont();
    $font->seek($this->entry->offset);
    $this->rawData = $font->read($this->entry->length);
  }
  function _encode() {
    return $this->getFont()->write($this->rawData, $this->entry->length);
  }
}
