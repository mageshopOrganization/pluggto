<?php

class Thirdlevel_Pluggto_Helper_Data extends Mage_Core_Helper_Abstract
{
    protected $StartTime;

    public function config()
    {
        $config = Mage::getStoreConfig('pluggto');
        return $config;
    }

    public function getExtensionVersion()
    {
        return (string)Mage::getConfig()->getNode()->modules->Thirdlevel_Pluggto->version;
    }

    public function WriteLogForModule($logType, $logTextOrVariable, $overrideModuleLogSettings = false)
    {
        $detailedLog = false;
        $debugLog    = false;

        // Set proper debug file
        switch ($logType) {
            case "Info":
                $logFile = 'pluggto_info.log';
                // Check if detailed log is enabled
                $detailedLog = Mage::getStoreConfig('pluggto/configs/log_detailed');

                if ($detailedLog == null) {
                    $detailedLog = false;
                }
                break;
            case "Debug":
                //Write Debug File
                $logFile = 'pluggto_debug.log';

                // Check if debug log is enabled
                $debugLog = Mage::getStoreConfig('pluggto/configs/log_detailed');

                if ($debugLog == null) {
                    $debugLog = false;
                }
                break;
            case "Error":
                //Write Error File
                $logFile = 'pluggto_error.log';
                $overrideModuleLogSettings = true;
                break;
            case "Call":
                $logFile = 'pluggto_calls.log';
                break;
            case "PERF": // instrumentacao de tempo; so grava se mageshop/perf_log ligado
                $logFile = 'pluggto_perf.log';
                $overrideModuleLogSettings = (bool) Mage::getStoreConfig('pluggto/mageshop/perf_log');
                break;
            default:
                $logFile = 'pluggto_misc.log';
        }

        // Write logs to files
        if (($detailedLog) || ($debugLog) || ($overrideModuleLogSettings)) {
            if (isset($logTextOrVariable)) {
                if ($logTextOrVariable != null) {
                    if (empty($logTextOrVariable)) {
                        Mage::log('Empty Variable: ', Zend_Log::DEBUG, $logFile, $overrideModuleLogSettings);
                    } else {
                        Mage::log($logTextOrVariable, Zend_Log::DEBUG, $logFile, $overrideModuleLogSettings);
                    }
                } else {
                    Mage::log('Null Variable: ', Zend_Log::DEBUG, $logFile);
                }
            } else {
                Mage::log('Non-set Variable: ', Zend_Log::DEBUG, $logFile);
            }
        }
    }
}