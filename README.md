# What is this?

This is a proof of concept of how to use SCSS as a template system to render websites on the server.

Here's an example (using [HTMX](https://htmx.org) for managing state)

```scss
.noselect {
  -webkit-touch-callout: none; // iOS Safari
  -webkit-user-select: none; // Safari
  -khtml-user-select: none; // Konqueror HTML
  -moz-user-select: none; // Old versions of Firefox
  -ms-user-select: none; // Internet Explorer/Edge
  user-select: none; // Non-prefixed version, currently supported by Chrome, Edge, Opera and Firefox
}

#app.noselect {
  font-family: Arial, Helvetica, sans-serif;
  position: fixed;
  left: 0;
  right: 0;
  top: 0;
  bottom: 0;
  background: #000;
  color: #fff;
  display: grid;
  justify-content: center;
  align-content: center;

  #click.btn[hx-post="/increase"][hx-swap="outerHTML"][hx-target="#app"][hx-select="#app"] {
    $active_background: rgb(195, 42, 11);
    $active_text: #fff;
    $inactive_text: #fff;

    $counter: 1;
    $content: "Click me (#{$counter})";

    cursor: pointer;
    border: 0.1rem solid transparent;
    border-radius: 3rem;
    padding: 1rem;
    background: transparent;
    color: $inactive_text;

    &:hover {
      background: $active_background;
      color: $active_text;
    }

    @function increaseCounter() {
      $counter: $counter + 1;
      $content: "Click me (#{$counter})";
    }
  }
}

```

![Peek 2024-02-05 05-28](https://github.com/tncrazvan/superstyle/assets/6891346/943178d7-c2bb-4b3a-8ba7-09f61db8c191)


# How to run

- Make sure `php-yaml` is installed (don't ask).

- Run
  ```bash
  composer prod:start
  ```

# Debugging

- Start your XDebug 3 listener
- Set your breakpoints
- Run
  ```bash
  composer dev:start
  ```

---

Refer to [catpaw](https://github.com/tncrazvan/catpaw?tab=readme-ov-file#get-started) for more details.
