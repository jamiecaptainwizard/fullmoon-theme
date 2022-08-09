/*-------------------*/
var element = null;

/*-------------------*/
function spoilersLoad() {
  bounceIn();
}

/*-------------------*/
function bounceIn() {
  animateCSS('.spoiler-image', 'bounceIn').then((message) => {
    tada();
  });;
}

/*-------------------*/
function tada() {
  animateCSS('.spoiler-image', 'tada').then((message) => {
    bounceOut();
  });;
}

/*-------------------*/
function bounceOut() {
  animateCSS('.spoiler-image', 'bounceOut').then((message) => {
    const node = document.querySelector('.spoiler-image');
    node.style.display = "none";
  });
}

/*-------------------*/
const animateCSS = (element, animation, prefix = 'animate__') =>
  // We create a Promise and return it
  new Promise((resolve, reject) => {
    const animationName = `${prefix}${animation}`;
    const node = document.querySelector(element);

    node.classList.add(`${prefix}animated`, animationName);

    // When the animation ends, we clean the classes and resolve the Promise
    function handleAnimationEnd(event) {
      event.stopPropagation();
      node.classList.remove(`${prefix}animated`, animationName);
      resolve('Animation ended');
    }

    node.addEventListener('animationend', handleAnimationEnd, {once: true});
  });