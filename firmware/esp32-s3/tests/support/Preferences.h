#pragma once

#include <cstdint>
#include <cstring>
#include <string>
#include <unordered_map>
#include <vector>

#include "Arduino.h"

class Preferences {
public:
    static void reset() { values_.clear(); failQueueWrite_ = false; }
    static void failNextQueueWrite() { failQueueWrite_ = true; }

    bool begin(const char *name, bool readOnly = false)
    {
        namespace_ = name;
        readOnly_ = readOnly;
        return true;
    }
    void end() {}
    bool clear()
    {
        if (readOnly_) return false;
        const std::string prefix = namespace_ + ":";
        for (auto it = values_.begin(); it != values_.end();) {
            it = it->first.rfind(prefix, 0) == 0 ? values_.erase(it) : std::next(it);
        }
        return true;
    }

    size_t getBytesLength(const char *key) const
    {
        auto it = values_.find(fullKey(key));
        return it == values_.end() ? 0 : it->second.size();
    }
    bool isKey(const char *key) const { return values_.find(fullKey(key)) != values_.end(); }
    size_t getBytes(const char *key, void *out, size_t size) const
    {
        auto it = values_.find(fullKey(key));
        if (it == values_.end() || it->second.size() > size) return 0;
        std::memcpy(out, it->second.data(), it->second.size());
        return it->second.size();
    }
    size_t putBytes(const char *key, const void *data, size_t size)
    {
        if (readOnly_) return 0;
        if (namespace_ == "haccp-queue" && failQueueWrite_) {
            failQueueWrite_ = false;
            return 0;
        }
        const auto *bytes = static_cast<const uint8_t *>(data);
        values_[fullKey(key)] = std::vector<uint8_t>(bytes, bytes + size);
        return size;
    }
    uint32_t getUInt(const char *key, uint32_t fallback = 0) const
    {
        uint32_t result = fallback;
        return getBytes(key, &result, sizeof(result)) == sizeof(result) ? result : fallback;
    }
    size_t putUInt(const char *key, uint32_t value) { return putBytes(key, &value, sizeof(value)); }
    bool getBool(const char *key, bool fallback = false) const
    {
        bool result = fallback;
        return getBytes(key, &result, sizeof(result)) == sizeof(result) ? result : fallback;
    }
    size_t putBool(const char *key, bool value) { return putBytes(key, &value, sizeof(value)); }
    String getString(const char *key) const
    {
        auto it = values_.find(fullKey(key));
        return it == values_.end() ? String() : String(std::string(it->second.begin(), it->second.end()));
    }
    size_t putString(const char *key, const String &value)
    {
        return putBytes(key, value.str().data(), value.length());
    }

private:
    std::string fullKey(const char *key) const { return namespace_ + ":" + key; }
    std::string namespace_;
    bool readOnly_ = false;
    inline static std::unordered_map<std::string, std::vector<uint8_t>> values_{};
    inline static bool failQueueWrite_ = false;
};
