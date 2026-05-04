func addf(a float32, b float32) float32 {
  return a + b
}

func main() {
  x := 3.5
  y := 2.5
  fmt.Println(addf(x, y))
  fmt.Println(true, "Hola", len("abc"), substr("Compilador", 0, 4), typeOf(10), now())
}
