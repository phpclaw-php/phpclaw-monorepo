# Known Issues

## API key field is a textarea, so the key is visible on screen

- **Why:** Joomla's `password` form field renders `maxlength="99"` whenever the field XML does not
  set `maxlength` (`libraries/src/Form/Field/PasswordField.php`, `$this->maxLength = ... : 99`).
  The browser enforces that limit when the value is pasted, so a longer key loses its tail before
  the form is submitted and PHP never sees the missing characters. Provider keys exceed 99: an
  Anthropic key is 108 characters, and OpenAI project keys are longer still. A truncated key is
  stored without any error and the provider then answers `401 API key is invalid`.
- **Current behaviour:** `api_key` in `plugin/phpclaw.xml` is `type="textarea"`, which has no
  length limit, so the whole key is accepted and the field wraps to show all of it.
- **Consequence:** a textarea cannot mask its content, so anyone who opens the plugin page sees the
  key in plain text. The field is behind Joomla's plugin-manager permissions, which are
  administrator level.
- **Alternative not taken:** `type="password"` with an explicit `maxlength` also accepts a long key
  and keeps the masking. It was not used because a fixed number has to be guessed high enough for
  every provider, and a key longer than the guess fails the same silent way.
