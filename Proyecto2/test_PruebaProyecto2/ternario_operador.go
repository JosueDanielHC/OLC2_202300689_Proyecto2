func f() int32 {
	fmt.Println(1)
	return 1
}

func g() int32 {
	fmt.Println(2)
	return 2
}

func main() {
	r := true ? f() : g()
	fmt.Println(r)
}
