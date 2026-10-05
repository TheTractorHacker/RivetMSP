<?php

namespace RivetMSP\KB;

/**
 * Compatibility alias: this class now lives in RivetCore (RivetCore\KB\PdfConverter). The alias keeps every
 * existing reference and static call working unchanged.
 *
 * PHP does not autoload a class named in a catch clause, so the matching exception alias is loaded here, with
 * the converter: any code that can call convert() can also `catch (\RivetMSP\KB\PdfConversionException $e)`.
 */
class_alias(\RivetCore\KB\PdfConverter::class, __NAMESPACE__ . '\PdfConverter');
class_exists(__NAMESPACE__ . '\PdfConversionException');
