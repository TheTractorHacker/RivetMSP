<?php

namespace RivetMSP\KB;

/**
 * Compatibility alias: this class now lives in RivetCore (RivetCore\KB\PdfConversionException). The alias keeps every existing
 * reference, `catch` clause and static call working unchanged.
 */
class_alias(\RivetCore\KB\PdfConversionException::class, __NAMESPACE__ . '\PdfConversionException');
