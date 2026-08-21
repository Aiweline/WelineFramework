<?php
/**
 * @package Weline_Framework Font (derived from php-font-lib)
 * @link    https://github.com/dompdf/php-font-lib (upstream)
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */

namespace Weline\Theme\Font\WOFF;

use Weline\Theme\Font\Table\DirectoryEntry;

/**
 * WOFF font file table directory entry.
 *
 * @package Weline_Framework Font (derived from php-font-lib)
 */
class TableDirectoryEntry extends DirectoryEntry {
  public $origLength;

  function __construct(File $font) {
    parent::__construct($font);
  }

  function parse() {
    parent::parse();

    $font             = $this->font;
    $this->offset     = $font->readUInt32();
    $this->length     = $font->readUInt32();
    $this->origLength = $font->readUInt32();
    $this->checksum   = $font->readUInt32();
  }
}
