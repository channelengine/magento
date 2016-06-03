setlocal
set MAGENTO_PLUGIN=C:\projects\channelengine-magento
set MAGENTO_ROOT=C:\projects\magento

echo %MAGENTO_PLUGIN%
echo %MAGENTO_ROOT%

mklink "%MAGENTO_ROOT%\app\etc\modules\Tritac_ChannelEngine.xml" "%MAGENTO_PLUGIN%\app\etc\modules\Tritac_ChannelEngine.xml"
mklink "%MAGENTO_ROOT%\app\design\frontend\base\default\layout\channelengine.xml" "%MAGENTO_PLUGIN%\app\design\frontend\base\default\layout\channelengine.xml"

mklink /D "%MAGENTO_ROOT%\app\code\community\Tritac\ChannelEngine" "%MAGENTO_PLUGIN%\app\code\community\Tritac\ChannelEngine"
mklink /D "%MAGENTO_ROOT%\app\design\adminhtml\default\default\template\channelengine" "%MAGENTO_PLUGIN%\app\design\adminhtml\default\default\template\channelengine"
mklink /D "%MAGENTO_ROOT%\app\design\frontend\base\default\template\channelengine" "%MAGENTO_PLUGIN%\app\design\frontend\base\default\template\channelengine"
mklink /D "%MAGENTO_ROOT%\lib\Tritac\ChannelEngineApiClient" "%MAGENTO_PLUGIN%\lib\Tritac\ChannelEngineApiClient"