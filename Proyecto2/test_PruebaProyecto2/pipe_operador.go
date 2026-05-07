func f(x int32) int32 {
	return x + 2
}

func g(x int32) int32 {
	return x * 3
}

func h(x int32) int32 {
	return x - 1
}

func main() {
	r := 2 |> f() |> g() |> h()
	fmt.Println(r)
}
