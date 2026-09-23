#pragma once

#include <cmath>
#include <cstdint>
#include <string>

class String {
public:
    String() = default;
    String(const char *value) : value_(value) {}
    String(const std::string &value) : value_(value) {}
    size_t length() const { return value_.length(); }
    bool startsWith(const char *prefix) const { return value_.rfind(prefix, 0) == 0; }
    bool operator!=(const String &other) const { return value_ != other.value_; }
    const std::string &str() const { return value_; }
private:
    std::string value_;
};

template <typename T> T max(T left, T right) { return left > right ? left : right; }
template <typename T> T constrain(T value, T lower, T upper)
{
    return value < lower ? lower : value > upper ? upper : value;
}
