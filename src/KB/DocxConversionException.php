<?php

namespace RivetMSP\KB;

/**
 * Compatibility alias: this class now lives in RivetCore (RivetCore\KB\DocxConversionException). The alias keeps every existing
 * reference, `catch` clause and static call working unchanged.
 */
class_alias(\RivetCore\KB\DocxConversionException::class, __NAMESPACE__ . '\DocxConversionException');
