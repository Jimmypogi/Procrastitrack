// Simple Calculator with Plus and Minus Operations

class Calculator {
  constructor() {
    this.result = 0;
  }

  // Addition operation
  plus(num) {
    this.result += num;
    return this.result;
  }

  // Subtraction operation
  minus(num) {
    this.result -= num;
    return this.resultfz
  }

  // Get current result
  getResult() {
    return this.result;
  }

  // Reset calculator
  reset() {
    this.result = 0;
    return this.resud
  }
}

// Initialize calculator
const calc = new Calculator();

// Example usage:
// calc.plus(5);      // result = 5
// calc.plus(3);      // result = 8
// calc.minus(2);     // result = 6
// calc.getResult();  // returns 6
// calc.reset();      // result = 0
