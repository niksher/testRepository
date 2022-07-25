<?php

namespace Model\Banner;

use Model\Helper\CacheHelper;
use Model\Helper\ServerHelper;
use Model\Helper\TranslateHelper;
use Model\Base\Config;
use Model\User\UserTable;

class BannerAction
{
    const BANNER_DIR = "/images/banner/";

    /**
     * Получить все банеры
     *
     * @return array
     */
    public function get(): array
    {
        $banners = BannerTable::getAll();
        $out = [];
        foreach ($banners as $banner) {
            $out[] = $this->response($banner);
        }

        return $out;
    }

    /**
     * Обновить банеры, удалить, которых не стало, сбросить кэш, и вернуть все банеры
     *
     * @param array $data
     * @param string $authKey
     * @return array
     * @throws \Exception
     */
    public function update(array $data, string $authKey): array
    {
        $user = UserTable::getByAuth($authKey);
        if (!isset($user->id) || !$user->isAdmin()) {
            throw new \Exception(TranslateHelper::get("access_denied"));
        }
        $this->deleteNotPresented($data);
        foreach ($data as $counter => $bannerData) {
            $this->updateBanner($bannerData, $counter);
        }
        CacheHelper::flushAll();

        return $this->get();
    }

    /**
     * Обновить банер
     *
     * @param BannerData $bannerData
     * @param int $order
     * @throws \Exception
     */
    private function updateBanner(BannerData $bannerData, int $order): void
    {
        $banner = BannerTable::getById($bannerData->id);
        if (!isset($banner->id)) {
            $banner = new BannerTable();
        }
        $banner->link = $bannerData->link;
        $banner->order = $order;
        //сохранить для получения id, для сохранения изображений
        $banner->save();

        $imgageTypes = Config::get("banner.languages") + ["preview"];
        foreach ($imgageTypes as $imageType) {
            $banner->$imageType = $this->savePicture(
                $banner->id,
                $bannerData->$imageType,
                $banner->$imageType,
                $order,
                $imageType
            );
        }

        $banner->save();
    }

    /**
     * Удалить изображения, которых не стало, сохранить изображение, вернуть имя файла
     *
     * @param int $bannerId
     * @param string $newImage
     * @param string $oldImage
     * @param int $order
     * @param string $imageType
     * @return string|null
     * @throws \Exception
     */
    private function savePicture(
        int $bannerId,
        string $newImage,
        string $oldImage,
        int $order,
        string $imageType
    ): ?string {
        if ($newImage === "" && $oldImage !== "") {
            $file = $this->getPath($bannerId, $oldImage);
            $this->deleteFile($file);
        }

        $filename = $this->copyImage($bannerId, $order, $imageType);
        if ($filename) {
            if ($oldImage !== "") {
                $file = $this->getPath($bannerId, $oldImage);
                $this->deleteFile($file);
            }
            return $filename;
        }
        if ($newImage !== "") {
            return basename($newImage);
        }

        return "";
    }

    /**
     * Удалить банеры, которых не стало, файлы и из бд
     *
     * @param array $data
     */
    private function deleteNotPresented(array $data): void
    {
        $bannersOut = [];
        foreach ($data as $bannerData) {
            $bannersOut[$bannerData["id"]] = $bannerData;
        }
        $banners = BannerTable::getAll();
        foreach ($banners as $banner) {
            if (!isset($bannersOut[$banner->id])) {
                $this->deleteImages($banner);
                $banner->delete();
            }
        }
    }

    /**
     * Удалить все изображения старого банера
     *
     * @param BannerTable $banner
     */
    private function deleteImages(BannerTable $banner): void
    {
        if (!isset($banner->id)) {
            return;
        }
        $path = $this->getPath($banner->id);

        $imageTypes = Config::get("banner.languages") + ["preview"];
        foreach ($imageTypes as $imageType) {
            $this->deleteFile($path . $banner->$imageType);
        }
    }

    /**
     * Удалить файл
     *
     * @param string $file
     */
    private function deleteFile(string $file): void
    {
        if (file_exists(realpath($file))) {
            unlink(realpath($file));
        }
    }

    /**
     * Сформировать ответ для фронта
     *
     * @param BannerTable $banner
     * @return BannerDTO
     */
    private function response(BannerTable $banner): BannerDTO
    {
        $url = $this->getPublicUrl();
        $response = new BannerDTO();
        $response->id = (int)$banner->id;
        $response->link = $banner->link;
        $imageTypes = Config::get("banner.languages") + ["preview"];
        foreach ($imageTypes as $imageType) {
            if ($banner->$imageType) {
                $response->$imageType = $url . $banner->$imageType;
            }
        }

        return $response;
    }

    /**
     * Копировать изображение и вернуть имя файла
     *
     * @param int $id
     * @param int $order
     * @param string $imageType
     * @return string|null
     * @throws \Exception
     */
    private function copyImage(int $id, int $order, string $imageType): ?string
    {
        $uploadedFile = $this->getUploadedFile($order, $imageType);
        if ($uploadedFile && file_exists($uploadedFile)) {
            $path = $this->getPath($id);
            $this->makeDir($path);
            $name = $this->randomName();
            copy($uploadedFile, $path . $name . ".jpg");

            return $name . ".jpg";
        }

        return null;
    }

    /**
     * Валидировать загружаемый файл и вернуть его название
     *
     * @param int $order
     * @param string $lang
     * @return string|null
     */
    private function getUploadedFile(string $order, string $lang): ?string
    {
        $tmpName = $_FILES["data"]["tmp_name"][$order][$lang];
        $error = $_FILES["data"]['error'][$order][$lang];
        $size = $_FILES["data"]['size'][$order][$lang];
        if (!isset($tmpName) || $tmpName == '') {
            return null;
        }
        if (!isset($error) || is_array($error)) {
            return null;
        }
        if ($size > Config::get("file.maxSize")) {
            return null;
        }

        return $tmpName;
    }

    /**
     * Создать папку
     *
     * @param $path
     * @throws \Exception
     */
    private function makeDir($path): void
    {
        if (is_dir($path)) {
            return;
        }
        if (!mkdir($path, 0777, true)) {
            throw new \Exception(TranslateHelper::get("directory_create_error!"));
        }
    }

    /**
     * Сгенерировать рандомное имя и добавить timestamp в конец
     *
     * @param int $len
     * @return string
     */
    private function randomName(int $len = 10): string
    {
        $availableSymbols = "0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ";
        $symbolsForName = str_repeat($availableSymbols, ceil($len/strlen($availableSymbols)));
        $randomSymbols = str_shuffle($symbolsForName);

        return substr($randomSymbols, 1, $len) . time();
    }

    /**
     * Получить урл до изображений
     *
     * @param int $bannerId
     * @return string
     */
    private function getPublicUrl(int $bannerId): string
    {
        $fileDomain = Config::get("fileDomain");
        $domain = $fileDomain ?? ServerHelper::getFullDomain();

        return  $domain . static::BANNER_DIR . $bannerId . "/";
    }

    /**
     * Получить путь до изображений
     *
     * @param int $bannerId
     * @param string $bannerName
     * @return string
     */
    private function getPath(int $bannerId, string $bannerName = ""): string
    {
        return DIR_ROOT . static::BANNER_DIR . $bannerId . "/" . $bannerName;
    }
}