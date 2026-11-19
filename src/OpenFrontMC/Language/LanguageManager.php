<?php

declare(strict_types=1);

namespace OpenFrontMC\Language;

use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;

final class LanguageManager{

    private Config $messages;

    public function __construct(PluginBase $plugin, string $locale){
        $supported = ["en_US", "fa_IR"];
        if(!in_array($locale, $supported, true)){
            $locale = "en_US";
        }
        foreach($supported as $language){
            $plugin->saveResource("languages/" . $language . ".yml");
        }
        $path = $plugin->getDataFolder() . "languages/" . $locale . ".yml";
        $this->messages = new Config($path, Config::YAML);
    }

    /**
     * @param array<string, scalar> $parameters
     */
    public function get(string $key, array $parameters = []) : string{
        $message = $this->messages->get($key);
        if(!is_string($message)){
            $message = $key;
        }
        foreach($parameters as $name => $value){
            $message = str_replace("{" . $name . "}", (string) $value, $message);
        }
        $prefix = $this->messages->get("prefix", "");
        return (is_string($prefix) ? $prefix : "") . $message;
    }
}
